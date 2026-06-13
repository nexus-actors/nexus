<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

use function Co\run;

final class EchoHandler extends WebSocketHandler
{
    public function __construct(#[FromContext] private readonly WebSocketContext $ctx) {}

    #[Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }
}

#[CoversNothing]
final class WorkerModeWebSocketHandlerTest extends TestCase
{
    #[Test]
    public function handler_mode_echoes_message(): void
    {
        $port    = ForkedSwooleServerFixture::findFreePort();
        $fixture = new ForkedSwooleServerFixture('127.0.0.1', $port);

        $fixture->start(static function () use ($port): void {
            SwooleWorkerServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false)
                    ->enableWebSocket(true),
                factory: static function (ActorSystem $system): CompiledApplication {
                    return WsApplication::create($system)
                        ->ws('/ws/echo', EchoHandler::class)
                        ->compile();
                },
            );
        });

        try {
            $reply = null;
            run(static function () use ($port, &$reply): void {
                $client = new Client('127.0.0.1', $port);
                $client->upgrade('/ws/echo');
                $client->push('hi');
                $frame = $client->recv(2.0);
                $reply = $frame instanceof Frame
                    ? $frame->data
                    : null;
                $client->close();
            });

            self::assertSame('echo:hi', $reply);
        } finally {
            $fixture->shutdown();
        }
    }
}
