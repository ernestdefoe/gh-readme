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
use Illuminate\Contracts\Filesystem\Factory as Filesystem;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy a private repo's README images onto this forum.
 *
 * 🚨 Why this exists. Fetching the README works fine for a private repo — the
 * API call carries the configured token. The IMAGES do not: the markdown is
 * rewritten to `raw.githubusercontent.com/...`, and those URLs are loaded by
 * each reader's browser with no token at all. On a public repo that is
 * correct and free. On a private one every reader gets a 404, so the post goes
 * out with holes where the screenshots should be — and the person who pasted
 * it sees them fine, because their own browser is signed in to GitHub.
 *
 * So for private repos the bytes are fetched here, with the token, and stored
 * on the forum's own assets disk. Deduplicated by content hash, so pasting the
 * same README twice — or two repos sharing a logo — stores one copy.
 *
 * Public repos are left completely alone: their raw URLs already work, GitHub
 * serves them from a CDN, and copying them would only cost disk.
 */
class ImageMirror
{
    /** Where mirrored images live inside the public assets disk. */
    private const FOLDER = 'gh-readme';

    /**
     * 🚨 No SVG. These files are served from the forum's own origin, and an
     * SVG is a script container — mirroring one would turn "paste a README"
     * into stored XSS on the host domain. Raster only.
     */
    private const ALLOWED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Per image, and per README — a paste must not be able to fill the disk. */
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_IMAGES = 20;

    public function __construct(
        protected Filesystem $filesystem,
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log,
    ) {
    }

    /**
     * Rewrite every image in the markdown that points at this repo's raw URLs,
     * returning markdown whose images this forum can actually serve.
     *
     * Any image that cannot be mirrored keeps its original URL: the worst case
     * is the behaviour we had before, never a broken paste.
     */
    public function mirror(string $markdown, string $owner, string $repo): string
    {
        $token = $this->token();

        // Without a token there is nothing to mirror — a repo we can read
        // unauthenticated is a repo the reader's browser can read too.
        if ($token === null) {
            return $markdown;
        }

        $urls = $this->rawUrlsFor($markdown, $owner, $repo);

        if (! $urls) {
            return $markdown;
        }

        $client = new Client([
            'timeout' => 20,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'User-Agent' => 'flarum-gh-readme',
            ],
        ]);

        // 🚨 A separate, token-LESS client, used to ask the only question that
        // matters: can a reader load this image without being signed in? If
        // yes there is nothing to fix, whatever the repo's visibility says.
        $anonymous = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
            'headers' => ['User-Agent' => 'flarum-gh-readme'],
        ]);

        $replacements = [];
        $done = 0;

        foreach ($urls as $url) {
            if ($done >= self::MAX_IMAGES) {
                $this->log->info('[gh-readme] stopped mirroring at '.self::MAX_IMAGES.' images for '.$owner.'/'.$repo);
                break;
            }

            if ($this->publiclyReadable($anonymous, $url)) {
                continue;
            }

            $local = $this->store($client, $url);

            if ($local !== null) {
                $replacements[$url] = $local;
                $done++;
            }
        }

        return $replacements
            ? strtr($markdown, $replacements)
            : $markdown;
    }

    /**
     * Every raw.githubusercontent URL in the markdown that belongs to THIS
     * repository.
     *
     * 🚨 Scoped to the repo on purpose. This method decides what an
     * authenticated request will be made to, so it must never widen to
     * "whatever host the markdown names" — that is the SSRF the rest of this
     * extension is careful to avoid, and a token would be attached to it.
     */
    private function rawUrlsFor(string $markdown, string $owner, string $repo): array
    {
        $prefix = sprintf('https://raw.githubusercontent.com/%s/%s/', $owner, $repo);

        preg_match_all('~https://raw\.githubusercontent\.com/[^\s"\')>]+~i', $markdown, $matches);

        $urls = array_unique($matches[0] ?? []);

        return array_values(array_filter(
            $urls,
            fn (string $url) => stripos($url, $prefix) === 0
        ));
    }

    /**
     * Can somebody with no GitHub session load this image?
     *
     * 🚨 Asked per URL rather than inferred from the repository being private.
     * The intent — "leave public repos alone, they already work and GitHub
     * serves them from a CDN" — was written in a comment while the code
     * mirrored every image the moment a token existed. A public repo's
     * screenshots were being copied onto the forum's disk for no reason. This
     * is the actual test, and it is one cheap request.
     *
     * A failure to answer counts as NOT readable, so the image gets mirrored:
     * a wasted copy is better than a broken picture.
     */
    private function publiclyReadable(Client $client, string $url): bool
    {
        try {
            return $client->head($url)->getStatusCode() === 200;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Fetch one image with the token and put it on the assets disk.
     *
     * @return string|null the public URL, or null to leave the original alone
     */
    private function store(Client $client, string $url): ?string
    {
        try {
            $response = $client->get($url);

            if ($response->getStatusCode() !== 200) {
                $this->log->info('[gh-readme] image '.$url.' returned HTTP '.$response->getStatusCode());

                return null;
            }

            $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

            if (! isset(self::ALLOWED[$type])) {
                $this->log->info('[gh-readme] refusing to mirror '.$url.' of type '.($type ?: 'unknown'));

                return null;
            }

            // Read in chunks so a lying Content-Length cannot OOM the worker.
            $body = '';
            $stream = $response->getBody();

            while (! $stream->eof()) {
                $body .= $stream->read(65536);

                if (strlen($body) > self::MAX_BYTES) {
                    $this->log->info('[gh-readme] image '.$url.' exceeds the 5 MB cap');

                    return null;
                }
            }

            if ($body === '') {
                return null;
            }

            // 🚨 The filename comes from the CONTENT, never from the URL: it
            // deduplicates for free, and no part of a path this extension
            // writes to is attacker-shaped.
            $path = self::FOLDER.'/'.hash('sha256', $body).'.'.self::ALLOWED[$type];
            $disk = $this->filesystem->disk('flarum-assets');

            if (! $disk->exists($path)) {
                $disk->put($path, $body);
            }

            return $disk->url($path);
        } catch (Throwable $e) {
            $this->log->warning('[gh-readme] could not mirror '.$url.': '.$e->getMessage());

            return null;
        }
    }

    private function token(): ?string
    {
        $token = $this->settings->get('gh-readme.github_token');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }
}
