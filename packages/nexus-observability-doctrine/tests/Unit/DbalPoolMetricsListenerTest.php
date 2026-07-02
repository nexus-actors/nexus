<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Tests\Unit;

use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionCreated;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionDestroyed;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionPoisoned;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionReleased;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionTaken;
use Monadial\Nexus\Doctrine\Dbal\Event\PoolExhausted;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Doctrine\DbalPoolMetricsListener;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Duration;
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

#[CoversClass(DbalPoolMetricsListener::class)]
final class DbalPoolMetricsListenerTest extends TestCase
{
    #[Test]
    public function recordsPoolEventMetrics(): void
    {
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $listener = new DbalPoolMetricsListener($observability);
        $listener->onConnectionCreated(new ConnectionCreated('default'));
        $listener->onConnectionTaken(new ConnectionTaken('default', Duration::millis(5)));
        $listener->onPoolExhausted(new PoolExhausted('default', PoolStats::empty()));

        $reader->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.dbal.pool.connections.created', $names);
        self::assertContains('nexus.dbal.pool.connections.taken', $names);
        self::assertContains('nexus.dbal.pool.acquire.wait', $names);
        self::assertContains('nexus.dbal.pool.exhausted', $names);
    }

    #[Test]
    public function recordsReleaseDestroyAndPoisonMetrics(): void
    {
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $listener = new DbalPoolMetricsListener($observability);
        $listener->onConnectionReleased(new ConnectionReleased('default', Duration::millis(100)));
        $listener->onConnectionDestroyed(new ConnectionDestroyed('default'));
        $listener->onConnectionPoisoned(new ConnectionPoisoned('default', 'health-check-failed'));

        $reader->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.dbal.pool.connections.released', $names);
        self::assertContains('nexus.dbal.pool.connections.destroyed', $names);
        self::assertContains('nexus.dbal.pool.connections.poisoned', $names);
    }

    #[Test]
    public function disabledObservabilityRecordsNothing(): void
    {
        $listener = new DbalPoolMetricsListener(new NoopObservability());
        $listener->onConnectionCreated(new ConnectionCreated('default'));

        self::expectNotToPerformAssertions();
    }
}
