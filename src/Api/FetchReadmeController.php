<?php

/*
 * This file is part of ernestdefoe/gh-readme.
 *
 * Copyright (c) Ernest Defoe.
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Ernestdefoe\GhReadme\Api;

use Ernestdefoe\GhReadme\Service\GithubReadmeFetcher;
use Ernestdefoe\GhReadme\Service\MarkdownToHtml;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * POST /api/gh-readme/fetch
 *
 * Body: { "url": "https://github.com/owner/repo" }
 *
 * Auth: registered users only. Guests get a 401. Members may fetch PUBLIC
 * repos only; private repos (readable by the owner's token) are for admins.
 *
 * 🚨 Throttled here. Core's flood control covers only new posts and
 * discussions, NOT this route, and every uncached call costs GitHub API
 * quota on the owner's token plus image downloads.
 */
class FetchReadmeController implements RequestHandlerInterface
{
    /** Fetches per member per ten minutes; cached READMEs count too, they're cheap. */
    private const PER_MEMBER = 20;

    public function __construct(protected GithubReadmeFetcher $fetcher, protected CacheRepository $cache)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (! $actor->isAdmin()) {
            $bucket = 'gh-readme:rate:'.$actor->id.':'.intdiv(time(), 600);
            $this->cache->add($bucket, 0, 600);
            if ($this->cache->increment($bucket) > self::PER_MEMBER) {
                return $this->error('Too many README fetches — try again in a few minutes.', 429);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $url = isset($body['url']) ? (string) $body['url'] : '';

        if ($url === '' || mb_strlen($url) > 500) {
            return $this->error('Missing or oversized url field.', 422);
        }

        try {
            ['owner' => $owner, 'repo' => $repo] = $this->fetcher->parseRepoUrl($url);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        try {
            $result = $this->fetcher->fetch($owner, $repo, $actor->isAdmin());
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 502;
            }
            return $this->error($e->getMessage(), $code);
        }

        return new JsonResponse([
            'data' => [
                'type' => 'gh-readme',
                'id' => $owner . '/' . $repo,
                'attributes' => [
                    'markdown' => $result['markdown'],
                    /*
                     * 🚨 Rendered here, not left to the editor.
                     *
                     * The paste handler used to hand raw Markdown to a rich
                     * editor and trust it to parse it — which fof/rich-text
                     * does and Scribe, deliberately, does not. On a Scribe
                     * forum the source went in verbatim, one paragraph per
                     * line, and stayed that way: there is no Markdown
                     * extension there to rescue it at render time.
                     *
                     * Tiptap parses HTML natively in both drivers, so sending
                     * HTML removes the guess entirely.
                     */
                    'html' => (new MarkdownToHtml())->convert($result['markdown']),
                    'owner' => $result['owner'],
                    'repo' => $result['repo'],
                    'sourceUrl' => $result['sourceUrl'],
                    'cached' => $result['cached'] ?? false,
                ],
            ],
        ]);
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse([
            'errors' => [[
                'status' => (string) $status,
                'detail' => $message,
            ]],
        ], $status);
    }
}
