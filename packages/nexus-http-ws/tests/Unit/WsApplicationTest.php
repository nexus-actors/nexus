<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\EchoHandler;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\RecordingChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\DuplicateRouteException;
use Monadial\Nexus\Http\Ws\WsApplication;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WsApplication::class)]
final class WsApplicationTest extends TestCase
{
    #[Test]
    public function create_shortcut_wraps_a_fresh_http_application(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        self::assertInstanceOf(HttpApplication::class, $app->inner());
    }

    #[Test]
    public function http_method_delegation_works_and_request_handles(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->get('/hello', static fn() => new Psr7Response(200, [], 'world'));

        $resp = $app->compile()->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('world', (string) $resp->getBody());
    }

    #[Test]
    public function ws_registers_handler_route(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->ws('/ws/echo', EchoHandler::class);

        $compiled = $app->compile();
        self::assertTrue($compiled->hasWebSocketRoutes());
        self::assertNotNull($compiled->webSocketRouter()->match('/ws/echo'));
    }

    #[Test]
    public function channel_registers_channel_route(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->channel('/ws/room/{id}', RecordingChannelActor::class, 'id');

        $compiled = $app->compile();
        self::assertTrue($compiled->hasWebSocketRoutes());
        $m = $compiled->webSocketRouter()->match('/ws/room/lobby');
        self::assertSame(['id' => 'lobby'], $m['params'] ?? null);
    }

    #[Test]
    public function channel_rejects_empty_key(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));

        $this->expectException(InvalidArgumentException::class);
        $app->channel('/ws/x/{id}', RecordingChannelActor::class, '');
    }

    #[Test]
    public function duplicate_path_throws(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->ws('/ws/echo', EchoHandler::class);

        $this->expectException(DuplicateRouteException::class);
        $app->ws('/ws/echo', EchoHandler::class);
    }

    #[Test]
    public function compile_returns_compiled_ws_application(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        self::assertInstanceOf(CompiledWsApplication::class, $app->compile());
    }

    #[Test]
    public function decorate_wraps_pre_configured_http_application(): void
    {
        $http = HttpApplication::create(ActorSystem::create('t', new TestRuntime()));
        $http->get('/api/health', static fn() => new Psr7Response(200, [], 'ok'));
        $app = WsApplication::decorate($http, ActorSystem::create('t', new TestRuntime()));

        $resp = $app->compile()->handle(new ServerRequest('GET', '/api/health'));

        self::assertSame(200, $resp->getStatusCode());
    }
}
