<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerHttpServer;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;

use function Co\run;

final class EchoHandler implements WebSocketHandler
{
    public function __construct(private readonly WebSocketContext $ctx) {}

    #[Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }

    #[Override]
    public function onClose(int $closeCode): void
    {
        // no-op; echo handler has no close behaviour
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
            SwooleWorkerHttpServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false)
                    ->enableWebSocket(true),
                factory: static function (ActorSystem $system): SwooleCompiledHttpApp {
                    $http = HttpApp::create($system);

                    return SwooleHttpApp::wrap($http, $system)
                        ->webSocket(
                            '/ws/echo',
                            static fn(WebSocketContext $ctx): WebSocketHandler => new EchoHandler($ctx),
                        )
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
                $reply = $frame === false || $frame === true
                    ? null
                    : $frame->data;
                $client->close();
            });

            self::assertSame('echo:hi', $reply);
        } finally {
            $fixture->shutdown();
        }
    }
}
