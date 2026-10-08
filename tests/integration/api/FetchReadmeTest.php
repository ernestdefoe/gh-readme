<?php

namespace Ernestdefoe\GhReadme\Tests\integration\api;

use Ernestdefoe\GhReadme\Service\GithubReadmeFetcher;
use Ernestdefoe\GhReadme\Service\ImageMirror;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class FetchReadmeTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** Requests the fetcher sent to "GitHub". */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-gh-readme');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ]);

        // With a token configured the fetcher asks GitHub whether a repo is
        // private before reading it. Nothing here reaches GitHub.
        $this->setting('gh-readme.github_token', 'not-a-real-token');
    }

    /**
     * Answer GitHub calls without the network: whether the repo is private,
     * and its README.
     */
    private function github(bool $private): void
    {
        $handler = HandlerStack::create(fn (RequestInterface $request) => Create::promiseFor(
            str_ends_with($request->getUri()->getPath(), '/readme')
                ? new Response(200, [], json_encode([
                    'content' => base64_encode("# Hello\n\nSome **bold** words.\n"),
                    'encoding' => 'base64',
                    'html_url' => 'https://github.com/o/r/blob/main/README.md',
                ]))
                : new Response(200, [], json_encode(['private' => $private]))
        ));
        $handler->push(fn (callable $next) => function ($request, $options) use ($next) {
            $this->calls[] = (string) $request->getUri();

            return $next($request, $options);
        });

        $container = $this->app()->getContainer();
        $container->instance(GithubReadmeFetcher::class, new class($container->make(Cache::class), $container->make(SettingsRepositoryInterface::class), $container->make(LoggerInterface::class), $container->make(ImageMirror::class), new Client(['handler' => $handler, 'http_errors' => false])) extends GithubReadmeFetcher {
            public function __construct($cache, $settings, $log, $images, private Client $mock)
            {
                parent::__construct($cache, $settings, $log, $images);
            }

            protected function client(): Client
            {
                return $this->mock;
            }
        });
    }

    private function fetch(?int $actor, string $url): ResponseInterface
    {
        $request = $this->request('POST', '/api/gh-readme/fetch', array_filter([
            'authenticatedAs' => $actor,
            'json' => ['url' => $url],
        ]));

        // A guest has no session to carry a CSRF token; skip that check so the
        // controller's own answer is what is tested.
        return $this->send($request->withAttribute('bypassCsrfToken', true));
    }

    #[Test]
    public function a_guest_cannot_fetch()
    {
        $this->github(false);

        $this->assertSame(401, $this->fetch(null, 'https://github.com/o/r')->getStatusCode());
        $this->assertSame([], $this->calls, 'GitHub is never asked on a guest\'s behalf');
    }

    #[Test]
    public function a_member_gets_a_public_readme_as_markdown_and_html()
    {
        $this->github(false);

        $response = $this->fetch(2, 'https://github.com/o/r');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];
        $this->assertStringContainsString('# Hello', $attributes['markdown']);
        $this->assertStringContainsString('<h1>Hello</h1>', $attributes['html']);
        $this->assertStringContainsString('<strong>bold</strong>', $attributes['html']);
        $this->assertSame('o', $attributes['owner']);
        $this->assertSame('r', $attributes['repo']);
    }

    #[Test]
    public function a_private_readme_is_for_admins_only()
    {
        $this->github(true);

        $this->assertSame(404, $this->fetch(2, 'https://github.com/o/secret')->getStatusCode());
        $this->assertSame(200, $this->fetch(1, 'https://github.com/o/secret')->getStatusCode());
    }

    #[Test]
    public function anything_but_a_github_repo_url_is_refused_before_github_is_asked()
    {
        $this->github(false);

        $this->assertSame(422, $this->fetch(2, '')->getStatusCode());
        $this->assertSame(422, $this->fetch(2, 'https://example.com/o/r')->getStatusCode());
        $this->assertSame(422, $this->fetch(2, 'http://github.com/o/r')->getStatusCode());
        $this->assertSame([], $this->calls);
    }

    #[Test]
    public function members_are_rate_limited_and_admins_are_not()
    {
        $this->github(false);

        for ($n = 1; $n <= 20; $n++) {
            $this->assertSame(422, $this->fetch(2, '')->getStatusCode(), "fetch $n is within the limit");
        }
        $this->assertSame(429, $this->fetch(2, 'https://github.com/o/r')->getStatusCode());
        $this->assertSame([], $this->calls, 'A throttled fetch never reaches GitHub');

        for ($n = 1; $n <= 21; $n++) {
            $this->assertSame(422, $this->fetch(1, '')->getStatusCode());
        }
    }
}
