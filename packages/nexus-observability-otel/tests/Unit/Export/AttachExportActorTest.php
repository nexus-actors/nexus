<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Export;

use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\Export\ActorForwardingSpanExporter;
use Monadial\Nexus\Observability\Otel\Export\AsyncExportHandles;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Otel\Tests\Support\RecordingSpanExporter;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtelObservability::class)]
final class AttachExportActorTest extends TestCase
{
    #[Test]
    public function attachDrainsPreAttachSpansIntoInnerExporterAfterActorProcessesThem(): void
    {
        $spanExporter = new RecordingSpanExporter();
        $forwardingSpans = new ActorForwardingSpanExporter($spanExporter);

        $tracerProvider = new TracerProvider(new BatchSpanProcessor($forwardingSpans, Clock::getDefault()));
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader(new MetricInMemoryExporter()))
            ->build();

        $observability = new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
            'nexus',
            null,
            new AsyncExportHandles(
                spans: $forwardingSpans,
                metrics: null,
                logs: null,
                innerSpans: $spanExporter,
                innerMetrics: null,
                innerLogs: null,
            ),
        );

        // Create a span before the actor is attached: it lands on the SDK's batch
        // processor, which hands it to the forwarding exporter while still Buffering.
        $span = $observability->tracer()->startSpan('pre-attach-span');
        $span->end();
        $tracerProvider->forceFlush();

        self::assertSame([], $spanExporter->exported, 'span should not reach the inner exporter before attach');

        $runtime = new StepRuntime();
        $system = ActorSystem::create('attach-export-test', $runtime, clock: $runtime->clock());
        $observability->attachExportActor($system);
        $runtime->drain();

        self::assertNotSame([], $spanExporter->exported, 'attach() should drain buffered spans through the actor');
    }

    #[Test]
    public function attachExportActorThrowsWhenHandlesAreAbsent(): void
    {
        $tracerProvider = new TracerProvider(new BatchSpanProcessor(new RecordingSpanExporter(), Clock::getDefault()));
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader(new MetricInMemoryExporter()))
            ->build();

        $observability = new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $system = ActorSystem::create('attach-export-no-handles-test', new StepRuntime());

        $this->expectException(LogicException::class);
        $observability->attachExportActor($system);
    }

    #[Test]
    public function attachExportActorIsIdempotent(): void
    {
        $spanExporter = new RecordingSpanExporter();
        $forwardingSpans = new ActorForwardingSpanExporter($spanExporter);

        $tracerProvider = new TracerProvider(new BatchSpanProcessor($forwardingSpans, Clock::getDefault()));
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader(new MetricInMemoryExporter()))
            ->build();

        $observability = new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
            'nexus',
            null,
            new AsyncExportHandles(
                spans: $forwardingSpans,
                metrics: null,
                logs: null,
                innerSpans: $spanExporter,
                innerMetrics: null,
                innerLogs: null,
            ),
        );

        $system = ActorSystem::create('attach-export-idempotent-test', new StepRuntime());
        $observability->attachExportActor($system);

        // Second call must be a silent no-op: no ActorNameExistsException.
        $observability->attachExportActor($system);

        self::assertSame(1, $system->liveActorCount());
    }

    #[Test]
    public function endToEndFiberPipelineFlushesSpansThroughActorOnShutdown(): void
    {
        $spanExporter = new RecordingSpanExporter();
        $forwardingSpans = new ActorForwardingSpanExporter($spanExporter);

        $tracerProvider = new TracerProvider(
            new BatchSpanProcessor(
                $forwardingSpans,
                Clock::getDefault(),
                maxExportBatchSize: 1,
                scheduledDelayMillis: 5,
            ),
        );
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader(new MetricInMemoryExporter()))
            ->build();

        $observability = new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
            'nexus',
            null,
            new AsyncExportHandles(
                spans: $forwardingSpans,
                metrics: null,
                logs: null,
                innerSpans: $spanExporter,
                innerMetrics: null,
                innerLogs: null,
            ),
        );

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('attach-export-e2e-test', $runtime, observability: $observability);
        $observability->attachExportActor($system);

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($observability): void {
            $span = $observability->tracer()->startSpan('e2e-span');
            $span->end();
        });
        $runtime->scheduleOnce(Duration::millis(300), static fn() => $system->shutdown(Duration::seconds(2)));
        $system->run();

        self::assertNotSame([], $spanExporter->exported, 'shutdown should force-flush pending spans through the actor');
    }
}
