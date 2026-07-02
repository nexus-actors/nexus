<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Http\Ws\WebSocket\Exception\UnsupportedRouteException;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies the thread-mode boot-rejection invariant: a WebSocketRouter that
 * contains at least one channel-mode route throws UnsupportedRouteException
 * when assertNoChannelRoutes() is called. SwooleThreadServer calls this
 * method in WorkerStart before the app handles any request.
 */
#[CoversNothing]
final class ThreadModeChannelRejectionTest extends TestCase
{
    #[Test]
    public function channel_route_is_rejected_in_thread_mode(): void
    {
        $router = WebSocketRouter::build([
            WebSocketRoute::channel('/chat/{room}', stdClass::class, 'room'),
        ]);

        $this->expectException(UnsupportedRouteException::class);
        $this->expectExceptionMessage('channel-actor routes are not supported');

        $router->assertNoChannelRoutes();
    }

    #[Test]
    public function handler_route_is_accepted_in_thread_mode(): void
    {
        $router = WebSocketRouter::build([
            WebSocketRoute::handler('/ws/echo', stdClass::class),
        ]);

        // Must not throw.
        $router->assertNoChannelRoutes();
        self::assertTrue(true);
    }
}
