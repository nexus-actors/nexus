<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Ws\CompiledHttpApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(HttpApplication::class)]
final class HttpApplicationTest extends TestCase
{
    #[Test]
    public function get_route_is_registered_and_served(): void
    {
        $app = HttpApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->get('/hello', static fn() => new Psr7Response(200, [], 'world'));

        $resp = $app->compile()->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('world', (string) $resp->getBody());
    }

    #[Test]
    public function compile_returns_compiled_http_application(): void
    {
        $app = HttpApplication::create(ActorSystem::create('t', new TestRuntime()));

        self::assertInstanceOf(CompiledHttpApplication::class, $app->compile());
    }

    #[Test]
    public function middleware_is_delegated(): void
    {
        $app = HttpApplication::create(ActorSystem::create('t', new TestRuntime()));
        $mw = new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle($request);
            }
        };
        $returned = $app->middleware($mw);

        self::assertSame($app, $returned);
    }

    #[Test]
    public function inner_returns_underlying_http_app(): void
    {
        $app = HttpApplication::create(ActorSystem::create('t', new TestRuntime()));

        self::assertInstanceOf(HttpApp::class, $app->inner());
    }
}
