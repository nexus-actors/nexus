<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence\Tests\Unit;

use DateTimeImmutable;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Persistence\TracingEventStore;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use RuntimeException;
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
use function is_array;
use function iterator_to_array;

#[CoversClass(TracingEventStore::class)]
final class TracingEventStoreTest extends TestCase
{
    private InMemoryExporter $spanExporter;
    private TracerProvider $tracerProvider;
    private MetricInMemoryExporter $metricExporter;
    private ExportingReader $reader;
    private OtelObservability $observability;

    protected function setUp(): void
    {
        $this->spanExporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->spanExporter));
        $this->metricExporter = new MetricInMemoryExporter();
        $this->reader = new ExportingReader($this->metricExporter);
        $this->observability = new OtelObservability(
            $this->tracerProvider,
            MeterProvider::builder()->addReader($this->reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );
    }

    #[Test]
    public function persistSpansAndCountsEventsThenDelegates(): void
    {
        $inner = new InMemoryEventStore();
        $store = new TracingEventStore($inner, $this->observability);
        $id = PersistenceId::of('Order', 'order-1');

        $store->persist(
            $id,
            new EventEnvelope($id, 1, new EventStoreTestEvent('a'), EventStoreTestEvent::class, new DateTimeImmutable()),
            new EventEnvelope($id, 2, new EventStoreTestEvent('b'), EventStoreTestEvent::class, new DateTimeImmutable()),
        );

        // delegation: the inner store actually has the events
        $loaded = $store->load($id);
        $events = is_array($loaded)
            ? $loaded
            : iterator_to_array($loaded);
        self::assertCount(2, $events);

        $this->tracerProvider->forceFlush();
        $spans = $this->spanExporter->getSpans();
        $names = array_map(static fn ($span): string => $span->getName(), $spans);
        self::assertContains('EventStore.persist', $names);
        self::assertContains('EventStore.load', $names);

        $persistSpan = null;

        foreach ($spans as $span) {
            if ($span->getName() === 'EventStore.persist') {
                $persistSpan = $span;
            }
        }

        self::assertNotNull($persistSpan);
        self::assertSame('Order|order-1', $persistSpan->getAttributes()->get('nexus.persistence.id'));
        self::assertSame('Order', $persistSpan->getAttributes()->get('nexus.persistence.entity.type'));
        self::assertSame(2, $persistSpan->getAttributes()->get('nexus.persistence.event.count'));

        $this->reader->collect();
        $metricNames = array_map(static fn ($metric): string => $metric->name, $this->metricExporter->collect());
        self::assertContains('nexus.persistence.events.persisted', $metricNames);
        self::assertContains('nexus.persistence.operation.duration', $metricNames);
    }

    #[Test]
    public function disabledObservabilityDelegatesWithoutSpans(): void
    {
        $inner = new InMemoryEventStore();
        $store = new TracingEventStore($inner, new NoopObservability());
        $id = PersistenceId::of('Order', 'order-2');

        $store->persist(
            $id,
            new EventEnvelope($id, 1, new EventStoreTestEvent('x'), EventStoreTestEvent::class, new DateTimeImmutable()),
        );

        self::assertSame(1, $store->highestSequenceNr($id));
        $this->tracerProvider->forceFlush();
        self::assertCount(0, $this->spanExporter->getSpans());
    }

    #[Test]
    public function persistPropagatesStoreErrorAndMarksSpanError(): void
    {
        $inner = new class implements EventStore {
            public function persist(PersistenceId $id, EventEnvelope ...$events): void
            {
                throw new RuntimeException('db down');
            }

            public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
            {
                return [];
            }

            public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void {}

            public function highestSequenceNr(PersistenceId $id): int
            {
                return 0;
            }
        };

        $store = new TracingEventStore($inner, $this->observability);
        $id = PersistenceId::of('Order', 'order-3');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('db down');

        try {
            $store->persist($id);
        } finally {
            $this->tracerProvider->forceFlush();
            $spans = $this->spanExporter->getSpans();

            $persistSpan = null;

            foreach ($spans as $span) {
                if ($span->getName() === 'EventStore.persist') {
                    $persistSpan = $span;
                }
            }

            self::assertNotNull($persistSpan);
            self::assertSame('Error', $persistSpan->getStatus()->getCode());
        }
    }
}

final readonly class EventStoreTestEvent
{
    public function __construct(public string $value) {}
}
