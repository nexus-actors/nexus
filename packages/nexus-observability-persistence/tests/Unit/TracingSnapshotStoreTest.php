<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence\Tests\Unit;

use DateTimeImmutable;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Persistence\TracingSnapshotStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(TracingSnapshotStore::class)]
final class TracingSnapshotStoreTest extends TestCase
{
    #[Test]
    public function saveSpansAndCountsThenDelegates(): void
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

        $inner = new InMemorySnapshotStore();
        $store = new TracingSnapshotStore($inner, $observability);
        $id = PersistenceId::of('Order', 'order-1');
        $store->save($id, new SnapshotEnvelope($id, 5, new SnapshotStoreTestState('done'), SnapshotStoreTestState::class, new DateTimeImmutable()));

        self::assertNotNull($store->load($id)); // delegation

        $tracerProvider->forceFlush();
        $names = array_map(static fn ($span): string => $span->getName(), $spanExporter->getSpans());
        self::assertContains('SnapshotStore.save', $names);
        self::assertContains('SnapshotStore.load', $names);

        $reader->collect();
        $metricNames = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.persistence.snapshots.saved', $metricNames);
    }
}

final readonly class SnapshotStoreTestState
{
    public function __construct(public string $value) {}
}
