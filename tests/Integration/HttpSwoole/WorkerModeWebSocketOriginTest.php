<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Http\Toolkit\Middleware\OriginAllowlistMiddleware;
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

final class OriginEchoHandler extends WebSocketHandler
{
    public function __construct(#[FromContext] private readonly WebSocketContext $ctx) {}

    #[Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }
}

/**
 * SEC-010: a cross-site WebSocket upgrade is rejected before the 101 switch by
 * the Origin allow-list running in the pre-upgrade handshake pipeline. Only an
 * exact allowed Origin upgrades.
 */
#[CoversNothing]
final class WorkerModeWebSocketOriginTest extends TestCase
{
    private const string ALLOWED = 'https://app.example.com';

    private static ?ForkedSwooleServerFixture $fixture = null;

    private static int $port = 0;

    #[Test]
    public function disallowed_origin_is_rejected_before_upgrade(): void
    {
        self::assertSame(403, $this->upgradeStatus('https://evil.example.com'));
    }

    #[Test]
    public function missing_origin_is_rejected_before_upgrade(): void
    {
        self::assertSame(403, $this->upgradeStatus(null));
    }

    #[Test]
    public function allowed_origin_upgrades_and_echoes(): void
    {
        $reply = null;

        run(static function () use (&$reply): void {
            $client = new Client('127.0.0.1', self::$port);
            $client->setHeaders(['Origin' => self::ALLOWED]);

            if ($client->upgrade('/ws/echo')) {
                $client->push('hi');
                $frame = $client->recv(2.0);
                $reply = $frame instanceof Frame
                    ? $frame->data
                    : null;
            }

            $client->close();
        });

        self::assertSame('echo:hi', $reply);
    }

    public static function setUpBeforeClass(): void
    {
        self::$port = ForkedSwooleServerFixture::findFreePort();
        self::$fixture = new ForkedSwooleServerFixture('127.0.0.1', self::$port);

        $port = self::$port;
        self::$fixture->start(static function () use ($port): void {
            SwooleWorkerServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false)
                    ->enableWebSocket(true),
                factory: static function (ActorSystem $system): CompiledApplication {
                    return WsApplication::create($system)
                        ->wsMiddleware(new OriginAllowlistMiddleware([self::ALLOWED], allowMissingOrigin: false))
                        ->ws('/ws/echo', OriginEchoHandler::class)
                        ->compile();
                },
            );
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixture?->shutdown();
        self::$fixture = null;
    }

    private function upgradeStatus(?string $origin): int
    {
        $status = 0;

        run(static function () use ($origin, &$status): void {
            $client = new Client('127.0.0.1', self::$port);

            if ($origin !== null) {
                $client->setHeaders(['Origin' => $origin]);
            }

            (void) $client->upgrade('/ws/echo');
            $status = $client->statusCode;
            $client->close();
        });

        return $status;
    }
}
