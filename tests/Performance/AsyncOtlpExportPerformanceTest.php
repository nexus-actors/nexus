<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Otel\Export\ActorForwardingSpanExporter;
use Monadial\Nexus\Observability\Otel\Export\OtlpExportActor;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Increment;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

use function fwrite;
use function hrtime;
use function number_format;
use function sprintf;

use const STDOUT;

/**
 * Load benchmarks for the actorized async OTLP export path (Swoole).
 *
 * Non-functional requirements:
 *   - Producer-side export() (mailbox enqueue) must be cheap: >20K batches/sec sustained
 *     from an application coroutine, regardless of collector speed.
 *   - Application actors must keep >20K msg/sec while a SLOW collector (50 ms per flush)
 *     is draining export batches concurrently — quantified stall isolation.
 *
 * Loss note: the export mailbox is bounded(256, DropOldest) — under producer overload the
 * oldest batches are evicted in favor of fresh telemetry, so the drain count is expected
 * to be far below the enqueue count in the first benchmark. That is the design working.
 *
 * Run: docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance --filter=AsyncOtlp
 */
final class AsyncOtlpExportPerformanceTest extends TestCase
{
    public function testExportEnqueueThroughput(): void
    {
        $batches = 50_000;

        $inner = self::recordingExporter(0.0);
        $drained = 0;
        $enqueueNs = 0;

        $metrics = Benchmark::measure(
            "AsyncOtlpExport: {$batches} batch enqueues (fast collector, incl. drain window)",
            $batches,
            static function () use ($batches, $inner, &$drained, &$enqueueNs): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('otlp-enqueue-bench', $runtime);

                $exportActor = new OtlpExportActor($inner, null, null);
                $exportRef = $system->spawn($exportActor->props(), 'otlp-export');

                $forwarder = new ActorForwardingSpanExporter($inner);
                $forwarder->attach($exportRef);

                $runtime->scheduleOnce(
                    Duration::millis(1),
                    static function () use ($batches, $forwarder, &$enqueueNs): void {
                        // Time ONLY the enqueue loop — the producer-side cost an application
                        // coroutine actually pays. The drain window below is excluded.
                        $t0 = hrtime(true);

                        for ($i = 0; $i < $batches; ++$i) {
                            $forwarder->export(['s']);
                        }

                        $enqueueNs = hrtime(true) - $t0;
                    },
                );

                // Give the actor a short drain window, then stop.
                $runtime->scheduleOnce(Duration::millis(1_000), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });

                $system->run();
                $drained = $inner->batches;
            },
        );

        $enqueuesPerSecond = $batches / ($enqueueNs / 1_000_000_000);
        fwrite(STDOUT, $metrics->report() . "\n");
        fwrite(STDOUT, sprintf("  enqueue loop only: %s enqueues/sec\n", number_format($enqueuesPerSecond)));

        self::assertGreaterThan(
            50_000,
            $enqueuesPerSecond,
            'producer-side export() must sustain >50K batch enqueues/sec (it is a mailbox enqueue, no I/O)',
        );
        self::assertGreaterThan(0, $drained, 'the export actor must have drained batches to the collector');
    }

    public function testApplicationThroughputWithSlowCollector(): void
    {
        $messages = 100_000;

        $inner = self::recordingExporter(0.05);
        $processed = 0;

        $metrics = Benchmark::measure(
            "AsyncOtlpExport: {$messages} app messages while a 50ms-per-flush collector drains",
            $messages,
            static function () use ($messages, $inner, &$processed): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('otlp-isolation-bench', $runtime);

                $exportActor = new OtlpExportActor($inner, null, null);
                $exportRef = $system->spawn($exportActor->props(), 'otlp-export');

                $forwarder = new ActorForwardingSpanExporter($inner);
                $forwarder->attach($exportRef);

                /** @var Behavior<object> $appBehavior */
                $appBehavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$processed, $messages, $system): Behavior {
                        ++$processed;

                        if ($processed >= $messages) {
                            $system->shutdown(Duration::millis(200));
                        }

                        return Behavior::same();
                    },
                );
                $appRef = $system->spawn(Props::fromBehavior($appBehavior), 'app');

                $runtime->scheduleOnce(
                    Duration::millis(1),
                    static function () use ($messages, $appRef, $forwarder): void {
                        for ($i = 0; $i < $messages; ++$i) {
                            $appRef->tell(new Increment());

                            // Interleave telemetry pressure: one export batch per 50 app messages.
                            if ($i % 50 === 0) {
                                $forwarder->export(['s' . $i]);
                            }
                        }
                    },
                );

                $system->run();
            },
        );

        fwrite(STDOUT, $metrics->report() . "\n");

        self::assertSame($messages, $processed, 'all application messages must be processed');
        self::assertGreaterThan(
            20_000,
            $metrics->opsPerSecond,
            'application throughput must stay >20K msg/sec while the slow collector drains',
        );
    }

    /**
     * A collector double: records batch arrivals, optionally sleeping per flush.
     */
    private static function recordingExporter(float $sleepSeconds): SpanExporterInterface
    {
        return new class ($sleepSeconds) implements SpanExporterInterface {
            public int $batches = 0;

            public function __construct(private readonly float $sleepSeconds) {}

            #[Override]
            public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
            {
                ++$this->batches;

                if ($this->sleepSeconds > 0.0) {
                    Coroutine::sleep($this->sleepSeconds);
                }

                return new CompletedFuture(true);
            }

            #[Override]
            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            #[Override]
            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
    }
}
