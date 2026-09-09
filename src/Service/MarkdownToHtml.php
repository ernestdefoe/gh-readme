<?php

namespace Ernestdefoe\GhReadme\Service;

/*
 * 🚨 `Ernestdefoe`, lowercase d — the same as every other file here and as the
 * PSR-4 prefix in composer.json. This file declared `ErnestDefoe` and was
 * therefore not autoloadable at all: Composer matches the namespace prefix as
 * a string, so one capital letter makes the class invisible.
 *
 * It survived review because macOS has a case-insensitive filesystem, so the
 * FILE is always found locally and only the deployed Linux host disagrees.
 */

/**
 * Turns README Markdown into the small HTML vocabulary a Flarum post accepts.
 *
 * 🚨 This exists because "rich text editor" does not imply "understands
 * Markdown". The paste handler used to hand the raw Markdown to
 * `insertAtCursor(text, false)`, whose escape=false flag asks fof/rich-text's
 * Tiptap driver to PARSE it — which it does. Scribe is also Tiptap and
 * deliberately has no Markdown anywhere in its stack, so it inserted the source
 * verbatim: one paragraph per line, with every `#` and `**` intact, on a forum
 * with no Markdown extension to rescue them at render time.
 *
 * 🚨 Deliberately NOT GitHub's own rendered HTML, which is the obvious
 * alternative. GitHub emits wrapper divs, spans, anchor ids and syntax
 * highlighting classes; Scribe allows a small closed list of elements and
 * strips the rest at parse time, so a code block arrives as its text with the
 * markup silently removed. Emitting only what the vocabulary accepts means what
 * is stored is what was meant.
 *
 * Not a general Markdown implementation, and not trying to be. It covers what
 * READMEs are made of, and anything it does not recognise stays as text.
 */
class MarkdownToHtml
{
    public function convert(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $out = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Fenced code first: nothing inside it is Markdown.
            if (preg_match('/^\s*```+\s*([A-Za-z0-9_+-]*)\s*$/', $line, $m)) {
                $lang = $m[1] ?? '';
                $body = [];
                $i++;

                while ($i < $count && ! preg_match('/^\s*```+\s*$/', $lines[$i])) {
                    $body[] = $lines[$i];
                    $i++;
                }

                $i++; // the closing fence, or the end of the input

                $out[] = '<pre>' . $this->escape(implode("\n", $body)) . '</pre>';
                continue;
            }

            if (trim($line) === '') {
                $i++;
                continue;
            }

            if (preg_match('/^\s*(?:---+|\*\*\*+|___+)\s*$/', $line)) {
                $out[] = '<hr>';
                $i++;
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $level = strlen($m[1]);
                $out[] = "<h$level>" . $this->inline($m[2]) . "</h$level>";
                $i++;
                continue;
            }

            if (preg_match('/^\s*[-*+]\s+/', $line)) {
                [$html, $i] = $this->list($lines, $i, $count, 'ul', '/^\s*[-*+]\s+(.*)$/');
                $out[] = $html;
                continue;
            }

            if (preg_match('/^\s*\d+[.)]\s+/', $line)) {
                [$html, $i] = $this->list($lines, $i, $count, 'ol', '/^\s*\d+[.)]\s+(.*)$/');
                $out[] = $html;
                continue;
            }

            if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
                $quote = [$m[1]];
                $i++;

                while ($i < $count && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m2)) {
                    $quote[] = $m2[1];
                    $i++;
                }

                $out[] = '<blockquote><p>' . $this->inline(implode(' ', $quote)) . '</p></blockquote>';
                continue;
            }

            /*
             * 🚨 A paragraph is consecutive non-blank lines JOINED, not one line
             * each. Markdown wraps prose at 80 columns and means it to reflow;
             * emitting a paragraph per line is what made the pasted README look
             * shattered even where the formatting was otherwise right.
             */
            $para = [];

            while ($i < $count && trim($lines[$i]) !== '' && ! $this->startsBlock($lines[$i])) {
                $para[] = trim($lines[$i]);
                $i++;
            }

            if ($para !== []) {
                $out[] = '<p>' . $this->inline(implode(' ', $para)) . '</p>';
            }
        }

        return implode("\n", $out);
    }

    /** Would this line begin a different block? */
    private function startsBlock(string $line): bool
    {
        return (bool) preg_match('/^\s*(```|#{1,6}\s|[-*+]\s|\d+[.)]\s|>\s?|(?:---+|\*\*\*+|___+)\s*$)/', $line);
    }

    /**
     * @param list<string> $lines
     * @return array{0:string,1:int}
     */
    private function list(array $lines, int $i, int $count, string $tag, string $pattern): array
    {
        $items = [];

        while ($i < $count && preg_match($pattern, $lines[$i], $m)) {
            $text = $m[1];
            $i++;

            // A wrapped continuation line belongs to the item above it.
            while ($i < $count && trim($lines[$i]) !== '' && preg_match('/^\s{2,}\S/', $lines[$i]) && ! $this->startsBlock(trim($lines[$i]))) {
                $text .= ' ' . trim($lines[$i]);
                $i++;
            }

            $items[] = '<li>' . $this->inline($text) . '</li>';
        }

        return ['<' . $tag . '>' . implode('', $items) . '</' . $tag . '>', $i];
    }

    /**
     * Inline markup.
     *
     * 🚨 Code spans are extracted BEFORE anything else and put back afterwards,
     * so `**` inside `` `code` `` stays literal. Doing it in one pass of
     * alternating regexes turns a README's own examples into bold text, which is
     * a particularly embarrassing way to mangle documentation about formatting.
     */
    private function inline(string $text): string
    {
        $codes = [];

        $text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
            $codes[] = '<code>' . $this->escape($m[1]) . '</code>';

            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text) ?? $text;

        $text = $this->escape($text);

        // Links before emphasis: a link's text may itself be bold.
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            return '<a href="' . $m[2] . '">' . $m[1] . '</a>';
        }, $text) ?? $text;

        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/', '<em>$1</em>', $text) ?? $text;

        return preg_replace_callback('/\x00(\d+)\x00/', fn ($m) => $codes[(int) $m[1]] ?? '', $text) ?? $text;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
