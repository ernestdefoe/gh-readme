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
 * Auth: registered users only. Guests get a 401. Anyone authenticated
 * can call this — there's no per-permission gate because (a) the data
 * is fully public (any README on github.com is web-readable already),
 * (b) the action is essentially a read-through cache, and (c) we
 * already constrain inputs to github.com and apply Flarum's standard
 * per-actor throttling.
 */
class FetchReadmeController implements RequestHandlerInterface
{
    public function __construct(protected GithubReadmeFetcher $fetcher)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

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
            $result = $this->fetcher->fetch($owner, $repo);
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
