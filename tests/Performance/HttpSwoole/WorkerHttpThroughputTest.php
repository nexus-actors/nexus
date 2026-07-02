<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\FreePort;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\LatencyRecorder;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\PerfReport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine\Http\Client;

use function Co\run;
use function hrtime;

#[CoversNothing]
final class WorkerHttpThroughputTest extends TestCase
{
    private const int SAMPLE_COUNT = 500;
    private const int P99_BUDGET_NANOS = 5_000_000;

    #[Test]
    public function worker_mode_serves_ping_within_p99_budget(): void
    {
        $port    = FreePort::find();
        $fixture = new ForkedSwooleServerFixture('127.0.0.1', $port);

        $fixture->start(static function () use ($port): void {
            SwooleWorkerServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false),
                factory: static function (ActorSystem $system): CompiledApplication {
                    $app = HttpApplication::create($system);
                    $app->get('/ping', static fn(): ResponseInterface => Response::ok());

                    return $app->compile();
                },
            );
        });

        try {
            $recorder = new LatencyRecorder();

            run(static function () use ($port, $recorder): void {
                $client = new Client('127.0.0.1', $port);
                $client->set(['timeout' => 5.0]);

                for ($i = 0; $i < self::SAMPLE_COUNT; $i++) {
                    $start = hrtime(true);
                    $client->get('/ping');
                    $elapsed = hrtime(true) - $start;

                    self::assertSame(200, $client->statusCode);

                    $recorder->record($elapsed);
                }

                $client->close();
            });

            $summary = $recorder->summary();
            PerfReport::write('worker-http-throughput', $summary);

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
