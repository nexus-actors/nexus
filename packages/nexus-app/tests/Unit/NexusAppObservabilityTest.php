<?php

declare(strict_types=1);

namespace Monadial\Nexus\App\Tests\Unit;

use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversNothing]
final class NexusAppObservabilityTest extends TestCase
{
    #[Test]
    #[WithoutErrorHandler]
    public function providedObservabilityInstrumentsActorsAndFlushesOnShutdown(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new BatchSpanProcessor($exporter, Clock::getDefault()));
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $runtime = new FiberRuntime();
        $app = NexusApp::create('app-obs-test')
            ->onStart(static function (ActorSystem $system) use ($runtime): void {
                $worker = $system->spawn(
                    Props::fromBehavior(
                        Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same()),
                    ),
                    'obs-worker',
                );
                $worker->tell(new AppObsPing());
                $runtime->scheduleOnce(Duration::millis(200), static fn() => $system->shutdown(Duration::seconds(1)));
            })
            ->withObservability($observability);

        // Batch not yet flushed — span must be absent before run().
        self::assertCount(0, $exporter->getSpans());

        $app->run($runtime);

        // span appears only after run() → NexusApp.shutdown() force-flushed the batch.
        $names = array_map(static fn($span): string => $span->getName(), $exporter->getSpans());
        self::assertContains('process AppObsPing', $names);
    }
}

final readonly class AppObsPing {}
