<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\HttpSwooleThreads;

use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\FreePort;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\LatencyRecorder;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\PerfReport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\Process;

use function Co\run;
use function hrtime;

use const PHP_BINARY;

#[CoversNothing]
final class ThreadHttpThroughputTest extends TestCase
{
    private const int SAMPLE_COUNT = 500;
    private const int P99_BUDGET_NANOS = 5_000_000;
    private const int THREAD_COUNT = 2;

    #[Test]
    public function thread_mode_serves_ping_within_p99_budget(): void
    {
        $port    = FreePort::find();
        $fixture = new ForkedSwooleServerFixture('127.0.0.1', $port);
        $script  = __DIR__ . '/../../Integration/HttpSwoole/Support/thread_http_server_bootstrap.php';

        $fixture->start(static function (Process $worker) use ($script, $port): void {
            // SWOOLE_THREAD re-runs the entry script per thread; swap to a
            // fresh PHP interpreter so phpunit is not re-executed.
            $worker->exec(PHP_BINARY, [$script, '127.0.0.1', (string) $port, (string) self::THREAD_COUNT]);
        });

        try {
            $recorder = new LatencyRecorder();

            run(static function () use ($port, $recorder): void {
                $client = new Client('127.0.0.1', $port);
                $client->set(['timeout' => 5.0]);

                for ($i = 0; $i < self::SAMPLE_COUNT; $i++) {
                    $start = hrtime(true);
                    $client->get('/hello');
                    $elapsed = hrtime(true) - $start;

                    self::assertSame(200, $client->statusCode);

                    $recorder->record($elapsed);
                }

                $client->close();
            });

            $summary = $recorder->summary();
            PerfReport::write('thread-http-throughput', $summary);

            self::assertSame(self::SAMPLE_COUNT, $summary['count']);
            self::assertLessThan(
                self::P99_BUDGET_NANOS,
                $summary['p99'],
                "P99 latency {$summary['p99']}ns exceeded budget " . self::P99_BUDGET_NANOS . 'ns',
            );
        } finally {
            $fixture->shutdown();
        }
    }
}
