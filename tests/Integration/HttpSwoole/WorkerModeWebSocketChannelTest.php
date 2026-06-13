<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ChannelChatBehavior;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

use function Co\run;
use function usleep;

#[CoversNothing]
final class WorkerModeWebSocketChannelTest extends TestCase
{
    #[Test]
    public function channel_broadcasts_within_same_worker(): void
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
                        ->channel(
                            '/ws/channel/{channelId}',
                            ChannelChatBehavior::class,
                            key: 'channelId',
                        )
                        ->compile();
                },
            );
        });

        try {
            $receivedByB = null;
            run(static function () use ($port, &$receivedByB): void {
                $a = new Client('127.0.0.1', $port);
                $a->upgrade('/ws/channel/room42');
                $b = new Client('127.0.0.1', $port);
                $b->upgrade('/ws/channel/room42');

                // Give both connections time to register with the channel actor.
                usleep(100_000);

                $a->push('hello-from-a');
                $frame = $b->recv(2.0);
                $receivedByB = $frame instanceof Frame
                    ? $frame->data
                    : null;

                $a->close();
                $b->close();
            });

            self::assertSame('hello-from-a', $receivedByB);
        } finally {
            $fixture->shutdown();
        }
    }
}
