<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerHttpServer;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ChannelChatBehavior;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;

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
            SwooleWorkerHttpServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false),
                factory: static function (ActorSystem $system): SwooleCompiledHttpApp {
                    $http = HttpApp::create($system);

                    return SwooleHttpApp::wrap($http, $system)
                        ->webSocketChannel(
                            '/ws/channel/{channelId}',
                            ChannelChatBehavior::props(),
                            keyFrom: 'channelId',
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
                $receivedByB = $frame === false || $frame === true
                    ? null
                    : $frame->data;

                $a->close();
                $b->close();
            });

            self::assertSame('hello-from-a', $receivedByB);
        } finally {
            $fixture->shutdown();
        }
    }
}
