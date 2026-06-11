<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\App;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRoute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(SwooleHttpApp::class)]
#[CoversClass(SwooleCompiledHttpApp::class)]
final class SwooleHttpAppTest extends TestCase
{
    #[Test]
    public function wrap_and_compile_returns_compiled_app(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $http = HttpApp::create($system);
        $http->get('/x', static fn(): ResponseInterface => Response::ok());

        $app = SwooleHttpApp::wrap($http, $system)->compile();

        self::assertInstanceOf(SwooleCompiledHttpApp::class, $app);
    }

    #[Test]
    public function websocket_handler_route_registered(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $http = HttpApp::create($system);

        $app = SwooleHttpApp::wrap($http, $system)
            ->webSocket('/ws/echo', static fn(WebSocketContext $ctx): WebSocketHandler =>
                new class implements WebSocketHandler {
                    public function onMessage(WebSocketFrame $frame): void
                    {
                    }

                    public function onClose(int $closeCode): void
                    {
                    }
                })
            ->compile();

        $match = $app->webSocketRouter()->match('/ws/echo');
        self::assertNotNull($match);
        self::assertSame(WebSocketRoute::MODE_HANDLER, $match['route']->mode);
    }

    #[Test]
    public function websocket_channel_route_registered_with_key(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $http = HttpApp::create($system);

        $props = Props::fromBehavior(
            Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same()),
        );

        $app = SwooleHttpApp::wrap($http, $system)
            ->webSocketChannel('/ws/channel/{channelId}', $props, keyFrom: 'channelId')
            ->compile();

        $match = $app->webSocketRouter()->match('/ws/channel/123');
        self::assertNotNull($match);
        self::assertSame(WebSocketRoute::MODE_CHANNEL, $match['route']->mode);
        self::assertSame('123', $match['params']['channelId']);
    }
}
