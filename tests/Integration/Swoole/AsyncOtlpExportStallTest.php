<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

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

use function hrtime;

/**
 * The decisive isolation proof for the actorized OTLP export: a STALLED collector
 * (an exporter whose flush blocks for seconds) must not block application actors.
 * All export I/O runs inside OtlpExportActor's coroutine; the forwarding exporter's
 * export() is a mailbox enqueue only.
 *
 * Note on loss accounting: the export actor's mailbox is bounded(256, DropOldest);
 * DropOldest EVICTS the oldest queued batch and returns Accepted for the new one,
 * so mailbox-level eviction under sustained overload is silent at the offer() seam
 * (favoring fresh telemetry). The `nexus.observability.export.dropped` counter fires
 * for buffer_full (pre-attach) and export_failed reasons. This test therefore proves
 * stall CONTAINMENT (the app keeps processing; only the first batch reached the
 * stalled inner exporter), not eviction accounting.
 */
final class AsyncOtlpExportStallTest extends TestCase
{
    public function testStalledCollectorDoesNotBlockApplicationActors(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('stall-test', $runtime);

        // Inner exporter that stalls hard on every export — simulates a hung collector.
        $stalling = new class implements SpanExporterInterface {
            /** Batches received — arrival proves the actor invoked us. */
            public int $batches = 0;

            #[Override]
            public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
            {
                ++$this->batches;
                Coroutine::sleep(6.0);

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

        $exportActor = new OtlpExportActor($stalling, null, null);
        $exportRef = $system->spawn($exportActor->props(), 'otlp-export');

        $forwarder = new ActorForwardingSpanExporter($stalling);
        $forwarder->attach($exportRef);

        // Application actor: counts processed messages.
        $processed = 0;

        /** @var Behavior<object> $appBehavior */
        $appBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$processed): Behavior {
            ++$processed;

            return Behavior::same();
        });
        $appRef = $system->spawn(Props::fromBehavior($appBehavior), 'app');

        $processedAtCheck = -1;
        $startNs = hrtime(true);

        // Inside coroutine context: stall the export actor, then flood the app actor.
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($forwarder, $appRef): void {
            // First batch reaches the actor and pins its coroutine in the 6s stall.
            $forwarder->export(['span-0']);

            for ($i = 0; $i < 1_000; ++$i) {
                $appRef->tell(new Increment());

                // Interleave more export batches — they queue behind the stalled one.
                if ($i % 100 === 0) {
                    $forwarder->export(['span-' . $i]);
                }
            }
        });

        // Well before the 6s stall ends: the app must have processed everything.
        $runtime->scheduleOnce(Duration::millis(2_000), function () use (&$processedAtCheck, &$processed, $system): void {
            $processedAtCheck = $processed;
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        $elapsedSeconds = (hrtime(true) - $startNs) / 1_000_000_000;

        self::assertSame(
            1_000,
            $processedAtCheck,
            'application actor must process all messages while the export coroutine is stalled',
        );
        self::assertSame(
            1,
            $stalling->batches,
            'only the first batch may reach the stalled inner exporter; the rest stay queued in the bounded mailbox',
        );
        self::assertLessThan(10.0, $elapsedSeconds, 'the whole test must stay within its wall-clock bound');
    }
}
