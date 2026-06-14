<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\HttpSwooleThreads;

use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\LatencyRecorder;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\PerfReport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\Process;

use function Co\run;

/**
 * Thread-mode WebSocket message round-trip latency regression guard.
 *
 * Phase 16 v1 limitation: channel-actor broadcast across threads is not yet
 * supported (the ChannelConnectionOpened payload is not serialization-safe
 * over Thread\Queue). This test instead measures handler-mode echo round-trip
 * latency on a 2-thread SwooleThreadServer — proves the message loop +
 * cross-process bridge work end-to-end under thread mode and gives us a
 * latency baseline to track.
 *
 * 500 sequential echo messages on a single connection. P99 < 50 ms.
 */
#[CoversNothing]
final class ThreadWebSocketChannelBroadcastTest extends TestCase
{
    private const int MESSAGE_COUNT = 500;
    private const int P99_BUDGET_NS = 50_000_000;

    #[Test]
    public function thread_mode_websocket_echo_p99_under_budget(): void
    {
        $port = ForkedSwooleServerFixture::findFreePort();
        $fixture = new ForkedSwooleServerFixture('127.0.0.1', $port);
        $bootstrap = __DIR__ . '/../../Integration/HttpSwoole/Support/thread_websocket_server_bootstrap.php';

        $fixture->start(static function (Process $worker) use ($bootstrap, $port): void {
            $worker->exec(PHP_BINARY, [$bootstrap, '127.0.0.1', (string) $port, '2']);
        });

        $recorder = new LatencyRecorder();

        try {
            run(static function () use ($port, $recorder): void {
                $client = new Client('127.0.0.1', $port);
                $client->upgrade('/ws/echo');

                for ($i = 0; $i < self::MESSAGE_COUNT; $i++) {
                    $start = hrtime(true);
                    $client->push("msg-{$i}");
                    $frame = $client->recv(2.0);

                    if ($frame !== false && $frame !== null) {
                        $recorder->record(hrtime(true) - $start);
                    }
                }

                $client->close();
            });

            $summary = $recorder->summary();
            PerfReport::write('thread_websocket_echo', $summary);

            self::assertGreaterThan(0, $summary['count']);
            self::assertLessThan(
                self::P99_BUDGET_NS,
                $summary['p99'],
                "P99 echo round-trip exceeded budget: {$summary['p99']}ns",
            );
        } finally {
            $fixture->shutdown();
        }
    }
}
