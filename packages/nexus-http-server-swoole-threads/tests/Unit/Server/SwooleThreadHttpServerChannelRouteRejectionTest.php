<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Tests\Unit\Server;

use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadHttpServer;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SwooleThreadHttpServer::class)]
final class SwooleThreadHttpServerChannelRouteRejectionTest extends TestCase
{
    #[Test]
    public function channel_route_is_rejected_in_thread_mode(): void
    {
        $router = WebSocketRouter::build([
            WebSocketRoute::channel('/ws/channel/{id}', self::neverHandledProps(), 'id'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('channel-actor routes are not supported in thread mode');

        SwooleThreadHttpServer::assertNoChannelRoutes($router);
    }

    #[Test]
    public function handler_routes_are_accepted_in_thread_mode(): void
    {
        $router = WebSocketRouter::build([
            WebSocketRoute::handler('/ws/echo', static fn() => new class {}),
        ]);

        SwooleThreadHttpServer::assertNoChannelRoutes($router);

        // No exception means handler routes pass.
        self::assertTrue(true);
    }

    #[Test]
    public function empty_router_is_accepted(): void
    {
        $router = WebSocketRouter::build([]);

        SwooleThreadHttpServer::assertNoChannelRoutes($router);

        self::assertTrue(true);
    }

    #[Test]
    public function rejection_message_names_the_offending_route_path(): void
    {
        $router = WebSocketRouter::build([
            WebSocketRoute::handler('/ws/echo', static fn() => new class {}),
            WebSocketRoute::channel('/ws/room/{roomId}', self::neverHandledProps(), 'roomId'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'/ws/room/{roomId}'");

        SwooleThreadHttpServer::assertNoChannelRoutes($router);
    }

    /**
     * @return Props<object>
     */
    private static function neverHandledProps(): Props
    {
        /** @var Props<object> */
        return Props::fromBehavior(Behavior::receive(
            /**
             * @psalm-suppress InvalidArgument
             *   Behavior::receive's template can't be inferred from the broad
             *   `object` param type in diff-mode runs. The behavior is never
             *   invoked in this test.
             */
            static fn() => Behavior::unhandled(),
        ));
    }
}
