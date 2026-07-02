<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ChannelChatBehavior;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\LatencyRecorder;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\PerfReport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;

use function Co\run;

/**
 * Worker-mode WebSocket channel-actor broadcast latency regression guard.
 *
 * 10 connections to the same channel; 50 broadcast iterations (sender → other 9
 * receive). Records nanosecond latency for each fanout. Asserts P99 stays
 * under 50 ms — a regression guard, not a target.
 */
#[CoversNothing]
final class WorkerWebSocketBroadcastTest extends TestCase
{
    private const int CONNECTION_COUNT = 10;
    private const int BROADCAST_ITERATIONS = 50;
    private const int P99_BUDGET_NS = 50_000_000;

    #[Test]
    public function channel_broadcast_p99_under_budget(): void
    {
        $port = ForkedSwooleServerFixture::findFreePort();
        $fixture = new ForkedSwooleServerFixture('127.0.0.1', $port);

        $fixture->start(static function () use ($port): void {
            SwooleWorkerServer::run(
                SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->enableWebSocket(true)
                    ->installSignalHandlers(false),
                static function (ActorSystem $system): CompiledApplication {
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

        $recorder = new LatencyRecorder();

        try {
            run(static function () use ($port, $recorder): void {
                $clients = [];

                for ($i = 0; $i < self::CONNECTION_COUNT; $i++) {
                    $client = new Client('127.0.0.1', $port);
                    $client->upgrade('/ws/channel/perf');
                    $clients[] = $client;
                }

                // Brief settle so all opens reach the channel actor.
                usleep(100_000);

                for ($iter = 0; $iter < self::BROADCAST_ITERATIONS; $iter++) {
                    $start = hrtime(true);
                    $clients[0]->push("msg-{$iter}");
                    // Wait for the 2nd connection to receive — proxy for fanout.
                    $frame = $clients[1]->recv(2.0);

                    if ($frame !== false && $frame !== null) {
                        $recorder->record(hrtime(true) - $start);
                    }
                }

                foreach ($clients as $c) {
                    $c->close();
                }
            });

            $summary = $recorder->summary();
            PerfReport::write('worker_websocket_broadcast', $summary);

            self::assertGreaterThan(0, $summary['count'], 'No broadcast samples collected');
            self::assertLessThan(
                self::P99_BUDGET_NS,
                $summary['p99'],
                "P99 broadcast fanout exceeded budget: {$summary['p99']}ns",
            );
        } finally {
            $fixture->shutdown();
        }
    }
}
