<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\WebSocket\Exception\UnsupportedRouteException;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketRouter::class)]
final class WebSocketRouterTest extends TestCase
{
    #[Test]
    public function matches_static_handler_path(): void
    {
        $r = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', FakeHandlerClass::class)]);
        $m = $r->match('/ws/echo');
        self::assertNotNull($m);
        self::assertSame(WebSocketRoute::MODE_HANDLER, $m['route']->mode);
        self::assertSame([], $m['params']);
    }

    #[Test]
    public function matches_channel_path_and_extracts_params(): void
    {
        $r = WebSocketRouter::build([
            WebSocketRoute::channel('/ws/room/{roomId}', FakeChannelActorClass::class, 'roomId'),
        ]);
        $m = $r->match('/ws/room/lobby');
        self::assertNotNull($m);
        self::assertSame(WebSocketRoute::MODE_CHANNEL, $m['route']->mode);
        self::assertSame(['roomId' => 'lobby'], $m['params']);
    }

    #[Test]
    public function returns_null_on_no_match(): void
    {
        $r = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', FakeHandlerClass::class)]);
        self::assertNull($r->match('/missing'));
    }

    #[Test]
    public function routes_accessor_returns_registered_routes(): void
    {
        $a = WebSocketRoute::handler('/ws/a', FakeHandlerClass::class);
        $b = WebSocketRoute::handler('/ws/b', FakeHandlerClass::class);
        $r = WebSocketRouter::build([$a, $b]);
        self::assertSame([$a, $b], $r->routes());
    }

    #[Test]
    public function assert_no_channel_routes_throws_when_channel_present(): void
    {
        $r = WebSocketRouter::build([
            WebSocketRoute::handler('/ws/echo', FakeHandlerClass::class),
            WebSocketRoute::channel('/ws/room/{id}', FakeChannelActorClass::class, 'id'),
        ]);
        $this->expectException(UnsupportedRouteException::class);
        $this->expectExceptionMessage('/ws/room/{id}');
        $r->assertNoChannelRoutes();
    }

    #[Test]
    public function assert_no_channel_routes_passes_for_handler_only(): void
    {
        $r = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', FakeHandlerClass::class)]);
        $r->assertNoChannelRoutes();
        self::assertTrue(true);
    }

    #[Test]
    public function empty_router_matches_nothing_and_returns_no_routes(): void
    {
        $r = WebSocketRouter::build([]);
        self::assertNull($r->match('/x'));
        self::assertSame([], $r->routes());
    }
}

/** @internal */
final class FakeHandlerClass {}

/** @internal */
final class FakeChannelActorClass {}
