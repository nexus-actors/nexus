<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Tests\Unit;

use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCleared;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCreated;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerEvicted;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Doctrine\OrmPoolMetricsListener;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
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

#[CoversClass(OrmPoolMetricsListener::class)]
final class OrmPoolMetricsListenerTest extends TestCase
{
    #[Test]
    public function recordsEntityManagerPoolMetrics(): void
    {
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $listener = new OrmPoolMetricsListener($observability);
        $listener->onEntityManagerCreated(new EntityManagerCreated('default'));
        $listener->onEntityManagerCleared(new EntityManagerCleared('default'));
        $listener->onEntityManagerEvicted(new EntityManagerEvicted('default', 'idle-timeout'));

        $reader->collect();
        $names = array_map(static fn($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.orm.pool.entity_managers.created', $names);
        self::assertContains('nexus.orm.pool.entity_managers.cleared', $names);
        self::assertContains('nexus.orm.pool.entity_managers.evicted', $names);
    }

    #[Test]
    public function disabledObservabilityRecordsNothing(): void
    {
        $listener = new OrmPoolMetricsListener(new NoopObservability());
        $listener->onEntityManagerCreated(new EntityManagerCreated('default'));
        $listener->onEntityManagerCleared(new EntityManagerCleared('default'));
        $listener->onEntityManagerEvicted(new EntityManagerEvicted('default', 'idle-timeout'));

        self::expectNotToPerformAssertions();
    }
}
