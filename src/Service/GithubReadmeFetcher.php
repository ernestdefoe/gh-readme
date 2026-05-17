<?php

/*
 * This file is part of ernestdefoe/gh-readme.
 *
 * Copyright (c) Ernest Defoe.
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Ernestdefoe\GhReadme\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fetches a GitHub repository's README via the documented REST API,
 * rewrites relative image and link paths to absolute raw URLs so they
 * render correctly when posted into a Flarum forum, and caches the
 * result so subsequent pastes of the same repo don't burn GitHub's
 * 60/hour-per-IP unauthenticated rate limit.
 *
 * Security (CLAUDE.md §14 SSRF):
 *   - Only `github.com` URLs accepted; the controller validates the
 *     input URL before passing owner/repo here.
 *   - Owner / repo names regex-allowlisted to GitHub's own charset.
 *   - We never call the user-supplied URL directly — we construct the
 *     `https://api.github.com/repos/{owner}/{repo}/readme` call
 *     ourselves, which means an attacker can't trick us into hitting
 *     `169.254.169.254` or RFC1918 hosts even if they pasted a
 *     malicious URL the controller's validator missed.
 *   - Response capped at 2 MB; README payloads are typically <200 KB
 *     and a runaway response would OOM the worker.
 *   - 10s connect + 15s total timeout; can't pin a worker on a slow
 *     resolver.
 */
class GithubReadmeFetcher
{
    /**
     * Match GitHub-permissible owner/repo names per github.com URL rules.
     *
     * The negative lookahead `(?!\.)` rejects leading-dot names — `.`,
     * `..`, `.hidden` — which while not actually exploitable here
     * (GitHub's API 404s on them and we never feed owner/repo into a
     * local FS path), follow CLAUDE.md §13's defense-in-depth stance:
     * traversal-shaped strings never reach an outbound request.
     */
    private const NAME_RE = '/\A(?!\.)[A-Za-z0-9._-]{1,100}\z/';

    /** Hard cap so a malicious upstream can't OOM the PHP worker. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** Cache TTL upper bound — admin setting overrides between 1–60 min. */
    private const DEFAULT_TTL_SECONDS = 600;

    public function __construct(
        protected CacheRepository $cache,
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log,
    ) {
    }

    /**
     * Parse a GitHub repo URL into ['owner' => ..., 'repo' => ...] or
     * throw InvalidArgumentException when the URL isn't a GitHub repo
     * root URL we recognize.
     *
     * Accepted shapes:
     *   https://github.com/owner/repo
     *   https://github.com/owner/repo/
     *   https://github.com/owner/repo/tree/<branch>      (ignored — we always fetch default branch README)
     *   http://  ← rejected (must be https)
     */
    public function parseRepoUrl(string $url): array
    {
        $url = trim($url);

        $parts = parse_url($url);
        if ($parts === false || ! is_array($parts)) {
            throw new InvalidArgumentException('Not a valid URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $path = $parts['path'] ?? '';

        if ($scheme !== 'https') {
            throw new InvalidArgumentException('Only https:// GitHub URLs are accepted.');
        }
        if ($host !== 'github.com' && $host !== 'www.github.com') {
            throw new InvalidArgumentException('URL must point to github.com.');
        }

        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        if (count($segments) < 2) {
            throw new InvalidArgumentException('URL is missing an owner/repo path.');
        }

        [$owner, $repo] = [$segments[0], $segments[1]];

        // Strip a trailing .git that some users paste from `git clone` examples.
        if (str_ends_with($repo, '.git')) {
            $repo = substr($repo, 0, -4);
        }

        if (! preg_match(self::NAME_RE, $owner) || ! preg_match(self::NAME_RE, $repo)) {
            throw new InvalidArgumentException('Owner or repo name has invalid characters.');
        }

        return ['owner' => $owner, 'repo' => $repo];
    }

    /**
     * Fetch and process the README for the given owner/repo. Returns
     * an array shaped:
     *   [
     *     'markdown' => "...processed markdown ready for the composer...",
     *     'owner'    => 'foo',
     *     'repo'     => 'bar',
     *     'sourceUrl'=> 'https://github.com/foo/bar',
     *     'cached'   => true|false,
     *   ]
     *
     * Throws RuntimeException on any GitHub failure (404, rate-limit,
     * network) — the controller turns these into a 4xx/5xx JSON
     * error the frontend handles.
     */
    public function fetch(string $owner, string $repo): array
    {
        $key = $this->cacheKey($owner, $repo);
        $ttl = $this->cacheTtl();

        if ($cached = $this->cache->get($key)) {
            return array_merge($cached, ['cached' => true]);
        }

        $client = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
            'headers' => $this->requestHeaders(),
        ]);

        $url = sprintf('https://api.github.com/repos/%s/%s/readme', rawurlencode($owner), rawurlencode($repo));

        try {
            $response = $client->get($url);
        } catch (RequestException $e) {
            $this->log->warning('[gh-readme] network failure for ' . $url . ': ' . $e->getMessage());
            throw new RuntimeException('Could not reach GitHub.', 502, $e);
        }

        $status = $response->getStatusCode();
        if ($status === 404) {
            throw new RuntimeException('README not found — repo may be private or have no README.', 404);
        }
        if ($status === 403) {
            $remaining = $response->getHeaderLine('X-RateLimit-Remaining');
            if ($remaining === '0') {
                throw new RuntimeException('GitHub rate limit reached. Try again in a few minutes.', 429);
            }
            throw new RuntimeException('GitHub denied the request (403).', 403);
        }
        if ($status >= 400) {
            throw new RuntimeException("GitHub returned HTTP $status.", $status >= 500 ? 502 : 400);
        }

        // Read body in capped chunks to defend against runaway upstream payloads.
        $body = '';
        $stream = $response->getBody();
        while (! $stream->eof()) {
            $body .= $stream->read(16384);
            if (strlen($body) > self::MAX_BYTES) {
                throw new RuntimeException('README exceeds 2 MB cap.', 413);
            }
        }

        $json = json_decode($body, true);
        if (! is_array($json) || ! isset($json['content']) || ! isset($json['encoding'])) {
            throw new RuntimeException('Unexpected GitHub response shape.', 502);
        }

        if ($json['encoding'] !== 'base64') {
            throw new RuntimeException("Unsupported README encoding: {$json['encoding']}.", 502);
        }

        $raw = base64_decode($json['content'], true);
        if ($raw === false) {
            throw new RuntimeException('README payload was not valid base64.', 502);
        }

        $defaultBranch = is_string($json['html_url'] ?? null)
            ? $this->extractBranchFromHtmlUrl($json['html_url'])
            : 'HEAD';

        $markdown = $this->processMarkdown($raw, $owner, $repo, $defaultBranch);

        $result = [
            'markdown' => $markdown,
            'owner' => $owner,
            'repo' => $repo,
            'sourceUrl' => "https://github.com/$owner/$repo",
        ];
        $this->cache->put($key, $result, $ttl);

        return array_merge($result, ['cached' => false]);
    }

    /**
     * Rewrite the README markdown so relative image / link / anchor
     * URLs resolve against `raw.githubusercontent.com` and the source
     * repo's HEAD branch, not the forum's domain. Without this,
     * `![logo](logo.png)` would 404 against the Flarum host.
     *
     * Also normalize Windows line endings — GitHub serves CRLF on
     * repos pushed from Windows clients and TextFormatter treats the
     * CR as a literal character in some block contexts.
     */
    private function processMarkdown(string $md, string $owner, string $repo, string $branch): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);

        $rawBase = sprintf('https://raw.githubusercontent.com/%s/%s/%s/', $owner, $repo, $branch);
        $blobBase = sprintf('https://github.com/%s/%s/blob/%s/', $owner, $repo, $branch);

        // Images: ![alt](path) — rewrite path if it's relative.
        $md = preg_replace_callback(
            '/!\[([^\]]*)\]\(\s*([^)\s]+)(\s+"[^"]*")?\s*\)/',
            function ($m) use ($rawBase) {
                $url = $this->resolveRelative($m[2], $rawBase);
                $title = $m[3] ?? '';
                return '![' . $m[1] . '](' . $url . $title . ')';
            },
            $md
        );

        // Links: [text](path) — rewrite if relative (use blob URL so user-clicked links land on github.com, not raw).
        $md = preg_replace_callback(
            '/(?<!\!)\[([^\]]+)\]\(\s*([^)\s]+)(\s+"[^"]*")?\s*\)/',
            function ($m) use ($blobBase) {
                $url = $m[2];
                if (str_starts_with($url, '#')) {
                    // In-document anchors don't resolve to anything useful when posted into Flarum;
                    // drop the link and keep the label text.
                    return $m[1];
                }
                $url = $this->resolveRelative($url, $blobBase);
                $title = $m[3] ?? '';
                return '[' . $m[1] . '](' . $url . $title . ')';
            },
            $md
        );

        // HTML <img src="..."> (GitHub READMEs commonly use these for sizing).
        $md = preg_replace_callback(
            '/<img\s+([^>]*?)src\s*=\s*"([^"]+)"/i',
            function ($m) use ($rawBase) {
                $url = $this->resolveRelative($m[2], $rawBase);
                return '<img ' . $m[1] . 'src="' . $url . '"';
            },
            $md
        );

        // HTML <a href="..."> in the README.
        $md = preg_replace_callback(
            '/<a\s+([^>]*?)href\s*=\s*"([^"#][^"]*)"/i',
            function ($m) use ($blobBase) {
                $url = $this->resolveRelative($m[2], $blobBase);
                return '<a ' . $m[1] . 'href="' . $url . '"';
            },
            $md
        );

        // Trim trailing whitespace per line; preserve blank lines for paragraph breaks.
        $md = preg_replace('/[ \t]+$/m', '', $md);

        return rtrim($md) . "\n";
    }

    /**
     * Resolve a possibly-relative URL against an absolute base. Leaves
     * already-absolute URLs (http/https/data/mailto) untouched.
     */
    private function resolveRelative(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === '') return $url;
        if (preg_match('/^(https?|data|mailto|ftp|tel):/i', $url)) return $url;
        if (str_starts_with($url, '//')) return 'https:' . $url;

        // Strip leading ./ and / so concat with $base works regardless.
        $url = ltrim($url, '/');
        if (str_starts_with($url, './')) {
            $url = substr($url, 2);
        }
        return $base . $url;
    }

    private function extractBranchFromHtmlUrl(string $htmlUrl): string
    {
        // html_url looks like: https://github.com/owner/repo/blob/HEAD/README.md
        if (preg_match('#/blob/([^/]+)/#', $htmlUrl, $m)) {
            return $m[1];
        }
        return 'HEAD';
    }

    private function requestHeaders(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'flarum-gh-readme-extension',
        ];

        $token = $this->settings->get('gh-readme.github_token');
        if (is_string($token) && trim($token) !== '') {
            $headers['Authorization'] = 'Bearer ' . trim($token);
        }

        return $headers;
    }

    private function cacheKey(string $owner, string $repo): string
    {
        return 'gh-readme:' . sha1(strtolower($owner) . '/' . strtolower($repo));
    }

    private function cacheTtl(): int
    {
        $minutes = (int) $this->settings->get('gh-readme.cache_minutes', 10);
        if ($minutes < 1) $minutes = 1;
        if ($minutes > 60) $minutes = 60;
        return $minutes * 60;
    }
}
