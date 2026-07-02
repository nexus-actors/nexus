<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\WorkerPool\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\WorkerPool\TracingWorkerTransport;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use Monadial\Nexus\WorkerPool\Transport\WorkerTransport;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_key_exists;
use function array_map;

#[CoversClass(TracingWorkerTransport::class)]
final class TracingWorkerTransportTest extends TestCase
{
    #[Test]
    public function sendInjectsContextOpensProducerSpanAndMeters(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $inner = new InMemoryWorkerTransport();
        $transport = new TracingWorkerTransport($inner, $observability);

        // Send within an active span so there is a context to propagate.
        $outer = $observability->tracer()->startSpan('outer', SpanKind::Internal);
        $envelope = Envelope::of(new WorkerPoolTestMsg(), ActorPath::root(), ActorPath::fromString('/user/target'));
        $transport->send(2, $envelope);
        $outer->end();

        $delivered = $inner->getSentTo(2);
        self::assertCount(1, $delivered);
        self::assertTrue(array_key_exists('traceparent', $delivered[0]->metadata));

        $tracerProvider->forceFlush();
        $spans = $spanExporter->getSpans();
        $producer = null;

        foreach ($spans as $span) {
            if ($span->getName() === 'worker.send') {
                $producer = $span;
            }
        }

        self::assertNotNull($producer);
        self::assertSame(3, $producer->getKind()); // PRODUCER
        self::assertSame(2, $producer->getAttributes()->get('nexus.worker.target'));

        $reader->collect();
        $metricNames = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.worker_pool.messages.sent', $metricNames);
        self::assertContains('nexus.worker_pool.send.duration', $metricNames);
    }

    #[Test]
    public function disabledObservabilityDelegatesWithoutInjectionOrSpans(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $inner = new InMemoryWorkerTransport();
        $transport = new TracingWorkerTransport($inner, new NoopObservability());

        $envelope = Envelope::of(new WorkerPoolTestMsg(), ActorPath::root(), ActorPath::fromString('/user/target'));
        $transport->send(1, $envelope);

        $delivered = $inner->getSentTo(1);
        self::assertCount(1, $delivered);
        self::assertArrayNotHasKey('traceparent', $delivered[0]->metadata);

        $tracerProvider->forceFlush();
        self::assertCount(0, $spanExporter->getSpans());
    }

    #[Test]
    public function delegatesListenAndStop(): void
    {
        $inner = new InMemoryWorkerTransport();
        $transport = new TracingWorkerTransport($inner, new NoopObservability());

        $received = [];
        $transport->listen(static function (Envelope $envelope) use (&$received): void {
            $received[] = $envelope;
        });
        $inner->receive(Envelope::of(new WorkerPoolTestMsg(), ActorPath::root(), ActorPath::fromString('/user/x')));
        self::assertCount(1, $received);

        self::assertFalse($transport->isStopped());
        $transport->stop();
        self::assertTrue($transport->isStopped());
    }

    #[Test]
    public function sendPropagatesTransportErrorAndMarksSpanError(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $inner = new class implements WorkerTransport {
            public function send(int $targetWorker, Envelope $envelope): void
            {
                throw new RuntimeException('transport down');
            }

            public function listen(callable $onEnvelope): void {}

            public function close(): void {}

            public function stop(): void {}

            public function isStopped(): bool
            {
                return false;
            }
        };

        $transport = new TracingWorkerTransport($inner, $observability);
        $envelope = Envelope::of(new WorkerPoolTestMsg(), ActorPath::root(), ActorPath::fromString('/user/target'));

        $caught = null;

        try {
            $transport->send(1, $envelope);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertSame('transport down', $caught->getMessage());

        $tracerProvider->forceFlush();

        $spans = $spanExporter->getSpans();
        $errorSpan = null;

        foreach ($spans as $span) {
            if ($span->getName() === 'worker.send') {
                $errorSpan = $span;
            }
        }

        self::assertNotNull($errorSpan);
        self::assertSame('Error', $errorSpan->getStatus()->getCode());
    }
}

final readonly class WorkerPoolTestMsg {}
