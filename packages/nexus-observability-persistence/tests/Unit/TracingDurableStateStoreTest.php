<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence\Tests\Unit;

use DateTimeImmutable;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Persistence\TracingDurableStateStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;
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

#[CoversClass(TracingDurableStateStore::class)]
final class TracingDurableStateStoreTest extends TestCase
{
    #[Test]
    public function upsertAndGetAreSpannedAndDelegated(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $inner = new InMemoryDurableStateStore();
        $store = new TracingDurableStateStore($inner, $observability);
        $id = PersistenceId::of('Cart', 'cart-1');
        $store->upsert($id, new DurableStateEnvelope($id, 1, new DurableStateTestState('full'), DurableStateTestState::class, new DateTimeImmutable()));

        self::assertNotNull($store->get($id)); // delegation

        $tracerProvider->forceFlush();
        $names = array_map(static fn ($span): string => $span->getName(), $spanExporter->getSpans());
        self::assertContains('DurableStateStore.upsert', $names);
        self::assertContains('DurableStateStore.get', $names);
    }
}

final readonly class DurableStateTestState
{
    public function __construct(public string $value) {}
}
