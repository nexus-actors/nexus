<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Tests\Unit\Test;

use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Toolkit\Test\HttpTestClient;
use Monadial\Nexus\Http\Toolkit\Test\TestResponse;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(HttpTestClient::class)]
#[CoversClass(TestResponse::class)]
final class HttpTestClientTest extends TestCase
{
    #[Test]
    public function get_dispatches_through_compiled_application(): void
    {
        $client = HttpTestClient::for(new StubApp(static function (ServerRequestInterface $req): ResponseInterface {
            return JsonResponse::ok([
                'method' => $req->getMethod(),
                'path'   => $req->getUri()->getPath(),
            ]);
        }));

        $client->get('/orders/42')
            ->assertOk()
            ->assertJsonPath('method', 'GET')
            ->assertJsonPath('path', '/orders/42');
    }

    #[Test]
    public function post_attaches_json_body_and_content_type(): void
    {
        $client = HttpTestClient::for(new StubApp(static function (ServerRequestInterface $req): ResponseInterface {
            return JsonResponse::ok([
                'contentType' => $req->getHeaderLine('Content-Type'),
                'body'        => (string) $req->getBody(),
            ]);
        }));

        $client->post('/orders', ['sku' => 'ABC', 'qty' => 2])
            ->assertOk()
            ->assertJsonPath('contentType', 'application/json')
            ->assertJsonPath('body', '{"sku":"ABC","qty":2}');
    }

    #[Test]
    public function with_header_chains_default_headers_into_requests(): void
    {
        $client = HttpTestClient::for(new StubApp(static function (ServerRequestInterface $req): ResponseInterface {
            return JsonResponse::ok([
                'auth' => $req->getHeaderLine('Authorization'),
                'trace' => $req->getHeaderLine('X-Trace'),
            ]);
        }));

        $client
            ->withBearerToken('xyz')
            ->withHeader('X-Trace', 'abc')
            ->get('/whoami')
            ->assertOk()
            ->assertJsonPath('auth', 'Bearer xyz')
            ->assertJsonPath('trace', 'abc');
    }

    #[Test]
    public function query_params_are_forwarded(): void
    {
        $client = HttpTestClient::for(new StubApp(static function (ServerRequestInterface $req): ResponseInterface {
            return JsonResponse::ok(['query' => $req->getQueryParams()]);
        }));

        $client->get('/search', ['q' => 'php', 'page' => 2])
            ->assertOk()
            ->assertJsonPath('query.q', 'php')
            ->assertJsonPath('query.page', '2');
    }

    #[Test]
    public function status_helpers_assert_correct_codes(): void
    {
        $client = HttpTestClient::for(new StubApp(static fn() => Response::notFound('gone')));

        $client->get('/missing')->assertNotFound();
    }
}

final class StubApp implements CompiledApplication
{
    /** @param callable(ServerRequestInterface): ResponseInterface $handler */
    public function __construct(private $handler) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handler)($request);
    }

    #[Override]
    public function hasWebSocketRoutes(): bool
    {
        return false;
    }
}
