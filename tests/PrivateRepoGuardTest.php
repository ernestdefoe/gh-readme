<?php

namespace Ernestdefoe\GhReadme\Tests;

use Ernestdefoe\GhReadme\Service\GithubReadmeFetcher;
use Ernestdefoe\GhReadme\Service\ImageMirror;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * 🚨 The token is the forum owner's and may read their private repos, while any
 * member can call the fetch endpoint. Private READMEs are for admins only.
 */
class PrivateRepoGuardTest extends TestCase
{
    private Repository $cache;

    protected function setUp(): void
    {
        $this->cache = new Repository(new ArrayStore());
    }

    private function fetcher(array $responses, ?array &$calls = null): GithubReadmeFetcher
    {
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(fn ($key) => $key === 'gh-readme.github_token' ? 'test-token' : null);

        $images = $this->createMock(ImageMirror::class);
        $images->method('mirror')->willReturnArgument(0);

        $mock = new MockHandler($responses);
        $calls = [];
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $next) use (&$calls) {
            return function ($request, $options) use ($next, &$calls) {
                $calls[] = (string) $request->getUri();

                return $next($request, $options);
            };
        });
        $client = new Client(['handler' => $stack, 'http_errors' => false]);

        return new class($this->cache, $settings, new NullLogger(), $images, $client) extends GithubReadmeFetcher {
            public function __construct($cache, $settings, $log, $images, private Client $mockClient)
            {
                parent::__construct($cache, $settings, $log, $images);
            }

            protected function client(): Client
            {
                return $this->mockClient;
            }
        };
    }

    private static function repo(bool $private): Response
    {
        return new Response(200, [], json_encode(['private' => $private]));
    }

    private static function readme(): Response
    {
        return new Response(200, [], json_encode([
            'content' => base64_encode("# Hello\n"),
            'encoding' => 'base64',
            'html_url' => 'https://github.com/o/r/blob/main/README.md',
        ]));
    }

    public function test_a_member_cannot_read_a_private_repo_and_its_readme_is_never_requested(): void
    {
        $fetcher = $this->fetcher([self::repo(true)], $calls);

        try {
            $fetcher->fetch('o', 'r', false);
            $this->fail('A member read a private README.');
        } catch (RuntimeException $e) {
            $this->assertSame(404, $e->getCode());
        }

        $this->assertSame(['https://api.github.com/repos/o/r'], $calls);
    }

    public function test_an_admin_can_read_a_private_repo(): void
    {
        $result = $this->fetcher([self::repo(true), self::readme()])->fetch('o', 'r', true);

        $this->assertStringContainsString('# Hello', $result['markdown']);
    }

    public function test_a_member_can_read_a_public_repo(): void
    {
        $result = $this->fetcher([self::repo(false), self::readme()])->fetch('o', 'r', false);

        $this->assertStringContainsString('# Hello', $result['markdown']);
    }

    public function test_a_private_readme_an_admin_cached_is_not_served_to_a_member(): void
    {
        $this->fetcher([self::repo(true), self::readme()])->fetch('o', 'r', true);

        $this->expectExceptionCode(404);
        $this->fetcher([])->fetch('o', 'r', false);
    }

    public function test_an_unclear_answer_from_github_counts_as_private(): void
    {
        $this->expectExceptionCode(404);
        $this->fetcher([new Response(500, [], 'oops')])->fetch('o', 'r', false);
    }
}
