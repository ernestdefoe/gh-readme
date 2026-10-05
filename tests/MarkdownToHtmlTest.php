<?php

namespace Ernestdefoe\GhReadme\Tests;

use Ernestdefoe\GhReadme\Service\MarkdownToHtml;
use PHPUnit\Framework\TestCase;

class MarkdownToHtmlTest extends TestCase
{
    /**
     * 🚨 The rule that was missing entirely.
     *
     * With no image rule, `![alt](url)` fell through to the LINK rule, which
     * matched the `[alt](url)` half and left the bang stranded — so every
     * screenshot in every README came out as a literal "!" followed by a link
     * to the .png, on posts whose whole point was the screenshots.
     */
    public function test_an_image_is_an_image_and_not_a_link_with_a_bang_in_front(): void
    {
        $html = (new MarkdownToHtml())->convert('![A screenshot](https://example.com/shot.png)');

        $this->assertStringContainsString('<img src="https://example.com/shot.png" alt="A screenshot">', $html);
        $this->assertStringNotContainsString('!<a', $html);
        $this->assertStringNotContainsString('<a href="https://example.com/shot.png"', $html);
    }

    public function test_a_plain_link_is_still_a_link(): void
    {
        $html = (new MarkdownToHtml())->convert('[the docs](https://example.com/docs)');

        $this->assertStringContainsString('<a href="https://example.com/docs">the docs</a>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    /** A README badge usually has no alt text at all. */
    public function test_an_image_with_no_alt_text(): void
    {
        $html = (new MarkdownToHtml())->convert('![](https://img.shields.io/badge/a-b.svg)');

        $this->assertStringContainsString('<img src="https://img.shields.io/badge/a-b.svg" alt="">', $html);
    }

    public function test_an_image_and_a_link_on_the_same_line(): void
    {
        $html = (new MarkdownToHtml())->convert('See [the docs](https://example.com) and ![shot](https://example.com/s.png)');

        $this->assertStringContainsString('<a href="https://example.com">the docs</a>', $html);
        $this->assertStringContainsString('<img src="https://example.com/s.png" alt="shot">', $html);
    }

    /**
     * A README is someone else's text. A javascript: or data: address in one
     * must not become a live link or image in the forum's HTML.
     */
    public function test_only_safe_addresses_become_links_and_images(): void
    {
        $md = new MarkdownToHtml();

        foreach (['javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=', 'vbscript:x', 'file:///etc/passwd'] as $bad) {
            $html = $md->convert('[click](' . $bad . ') and ![pic](' . $bad . ')');

            $this->assertStringNotContainsString('href=', $html, $bad);
            $this->assertStringNotContainsString('<img', $html, $bad);
            $this->assertStringContainsString('click', $html, $bad);
        }

        foreach (['https://example.com/a', 'http://example.com/a', 'mailto:me@example.com', '/docs/setup.md', 'docs/setup.md', '#installation', '//example.com/a'] as $good) {
            $this->assertStringContainsString('<a href="' . $good . '">x</a>', $md->convert('[x](' . $good . ')'), $good);
            $this->assertStringContainsString('<img src="' . $good . '"', $md->convert('![x](' . $good . ')'), $good);
        }
    }
}
