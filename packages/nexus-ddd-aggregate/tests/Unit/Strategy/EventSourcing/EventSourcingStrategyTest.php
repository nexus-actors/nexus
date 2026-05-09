<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Strategy\EventSourcing;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\InMemoryVersionedEventStore;
use Monadial\Nexus\Ddd\Aggregate\Event\Stream\SingleStreamStrategy;
use Monadial\Nexus\Ddd\Aggregate\Event\VersionedEventStore;
use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\EventSourcingStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\InMemoryEventSourcingStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\NeverSnapshot;
use Monadial\Nexus\Ddd\Aggregate\Versioning\DefaultUpcasterPipeline;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcingStrategy::class)]
#[CoversClass(InMemoryEventSourcingStrategy::class)]
final class EventSourcingStrategyTest extends TestCase
{
    #[Test]
    public function persistAppendsRecordedEventsAtCorrectExpectedVersion(): void
    {
        $store = new InMemoryVersionedEventStore();
        $strategy = $this->buildStrategy($store);
        $orderId = self::newId();
        $order = Order::placeNew($orderId);

        $strategy->persist($order);

        self::assertSame(
            1,
            $store->highestSequenceNr(PersistenceId::of(Order::class, $orderId->value())),
        );
    }

    #[Test]
    public function persistAfterLoadComputesExpectedVersionAsAggregateVersionMinusEventCount(): void
    {
        $store = new InMemoryVersionedEventStore();
        $strategy = $this->buildStrategy($store);
        $orderId = self::newId();
        $strategy->persist(Order::placeNew($orderId));

        $reloaded = $strategy->load(Order::class, $orderId)->getUnsafe();
        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->version());

        $reloaded->addLine('sku-1');
        $strategy->persist($reloaded);

        self::assertSame(
            2,
            $store->highestSequenceNr(PersistenceId::of(Order::class, $orderId->value())),
        );
    }

    #[Test]
    public function persistRaisesOptimisticLockOnConcurrentMutation(): void
    {
        $store = new InMemoryVersionedEventStore();
        $strategy = $this->buildStrategy($store);
        $orderId = self::newId();
        $strategy->persist(Order::placeNew($orderId));

        $a = $strategy->load(Order::class, $orderId)->getUnsafe();
        $b = $strategy->load(Order::class, $orderId)->getUnsafe();
        self::assertNotNull($a);
        self::assertNotNull($b);

        $a->addLine('sku-a');
        $strategy->persist($a);

        $b->addLine('sku-b');

        $this->expectException(OptimisticLockException::class);
        $strategy->persist($b);
    }

    #[Test]
    public function persistOfNewAggregateWithExistingIdRaisesAggregateAlreadyExists(): void
    {
        $store = new InMemoryVersionedEventStore();
        $strategy = $this->buildStrategy($store);
        $orderId = self::newId();
        $strategy->persist(Order::placeNew($orderId));

        $this->expectException(AggregateAlreadyExistsException::class);
        $strategy->persist(Order::placeNew($orderId));
    }

    #[Test]
    public function loadReturnsNoneWhenNoEventsAndNoSnapshot(): void
    {
        $strategy = $this->buildStrategy(new InMemoryVersionedEventStore());

        self::assertTrue($strategy->load(Order::class, self::newId())->isNone());
    }

    #[Test]
    public function loadReplaysEventsToReconstructAggregateState(): void
    {
        $store = new InMemoryVersionedEventStore();
        $strategy = $this->buildStrategy($store);
        $orderId = self::newId();

        $original = Order::placeNew($orderId);
        $original->addLine('sku-1');
        $original->addLine('sku-2');
        $strategy->persist($original);

        $reloaded = $strategy->load(Order::class, $orderId)->getUnsafe();

        self::assertNotNull($reloaded);
        self::assertSame(3, $reloaded->version());
        self::assertSame(['sku-1', 'sku-2'], $reloaded->lines);
        self::assertTrue($reloaded->placed);
    }

    #[Test]
    public function loadWithCompatibleSnapshotReadsTailEventsAndProducesCorrectVersion(): void
    {
        $innerStore = new InMemoryVersionedEventStore();
        $orderId = self::newId();
        $persistenceId = PersistenceId::of(Order::class, $orderId->value());

        // Persist an aggregate with 4 events (version becomes 4).
        $strategy = $this->buildStrategy($innerStore);
        $original = Order::placeNew($orderId);
        $original->addLine('sku-1');
        $original->addLine('sku-2');
        $original->addLine('sku-3');
        $strategy->persist($original);

        // Stub the snapshot store: return a compatible snapshot at sequence 2.
        // The loader MUST then read events 3..MAX from the EVENT store and the
        // resulting aggregate version must be 4 (snapshot baseline 2 + 2 events).
        $recordingStore = new RecordingVersionedEventStore($innerStore);
        $snapshotStore = new RecordingSnapshotStore();
        $snapshotStore->primed = new SnapshotEnvelope(
            $persistenceId,
            sequenceNr: 2,
            state: new OrderSnapshotState(),
            stateType: Order::class,
            timestamp: new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        );
        $strategyWithSnapshot = $this->buildStrategy($recordingStore, $snapshotStore);

        $reloaded = $strategyWithSnapshot->load(Order::class, $orderId)->getUnsafe();

        self::assertNotNull($reloaded);
        self::assertSame(4, $reloaded->version());
        self::assertNotNull($recordingStore->lastLoadFrom);
        self::assertSame(3, $recordingStore->lastLoadFrom);
    }

    #[Test]
    public function inMemoryStrategyWiresInMemoryStoresAndRoundTripsAggregate(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-05-08T12:00:00+00:00'));
        $strategy = new InMemoryEventSourcingStrategy(new DefaultUpcasterPipeline([]), $clock);
        $orderId = self::newId();
        $original = Order::placeNew($orderId);
        $original->addLine('sku-1');
        $strategy->persist($original);

        $reloaded = $strategy->load(Order::class, $orderId)->getUnsafe();

        self::assertNotNull($reloaded);
        self::assertSame(2, $reloaded->version());
        self::assertSame(['sku-1'], $reloaded->lines);
    }

    #[Test]
    public function loadWithIncompatibleSnapshotFallsBackToFullReplay(): void
    {
        $store = new InMemoryVersionedEventStore();
        $strategy = $this->buildStrategy($store);
        $orderId = self::newId();
        $original = Order::placeNew($orderId);
        $original->addLine('sku-1');
        $original->addLine('sku-2');
        $strategy->persist($original);

        $persistenceId = PersistenceId::of(Order::class, $orderId->value());
        $snapshotStore = new RecordingSnapshotStore();
        $snapshotStore->primed = new SnapshotEnvelope(
            $persistenceId,
            sequenceNr: 1,
            state: new OrderSnapshotState(),
            stateType: 'SomeOtherClass\\ThatNoLongerExists',
            timestamp: new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        );
        $strategyWithSnapshot = $this->buildStrategy($store, $snapshotStore);

        $reloaded = $strategyWithSnapshot->load(Order::class, $orderId)->getUnsafe();

        self::assertNotNull($reloaded);
        self::assertSame(3, $reloaded->version());
        self::assertSame(['sku-1', 'sku-2'], $reloaded->lines);
        self::assertTrue($reloaded->placed);
    }

    private function buildStrategy(
        VersionedEventStore $store,
        ?SnapshotStore $snapshotStore = null,
    ): EventSourcingStrategy {
        return new EventSourcingStrategy(
            $store,
            $snapshotStore ?? new InMemorySnapshotStore(),
            new DefaultUpcasterPipeline([]),
            new SingleStreamStrategy(),
            new NeverSnapshot(),
            new FixedClock(new DateTimeImmutable('2026-05-08T12:00:00+00:00')),
            new NullLogger(),
        );
    }

    private static function newId(): TestUlidId
    {
        return new TestUlidId((new Ulid())->toBase32());
    }
}

final class FixedClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now) {}

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

/**
 * Test double: returns a primed envelope from `load()`. Save/delete are
 * no-ops sufficient for the load-path tests.
 */
final class RecordingSnapshotStore implements SnapshotStore
{
    public ?SnapshotEnvelope $primed = null;

    #[Override]
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        $this->primed = $snapshot;
    }

    #[Override]
    public function load(PersistenceId $id): ?SnapshotEnvelope
    {
        return $this->primed;
    }

    #[Override]
    public function delete(PersistenceId $id, int $maxSequenceNr): void
    {
        // no-op
    }
}

/**
 * Test double: delegates to a backing store but records the `from` sequence
 * passed to `load()`. Used to verify the strategy reads the correct
 * sub-range of events when a snapshot is present.
 */
final class RecordingVersionedEventStore implements VersionedEventStore
{
    public ?int $lastLoadFrom = null;

    public function __construct(private readonly InMemoryVersionedEventStore $inner) {}

    #[Override]
    public function appendIfVersion(PersistenceId $persistenceId, int $expectedVersion, EventEnvelope ...$events): void
    {
        $this->inner->appendIfVersion($persistenceId, $expectedVersion, ...$events);
    }

    #[Override]
    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        $this->inner->persist($id, ...$events);
    }

    /** @return iterable<EventEnvelope> */
    #[Override]
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        $this->lastLoadFrom = $fromSequenceNr;

        return $this->inner->load($id, $fromSequenceNr, $toSequenceNr);
    }

    #[Override]
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $this->inner->deleteUpTo($id, $toSequenceNr);
    }

    #[Override]
    public function highestSequenceNr(PersistenceId $id): int
    {
        return $this->inner->highestSequenceNr($id);
    }
}

final readonly class OrderSnapshotState {}

/** @extends EventSourcedAggregateRoot<TestUlidId, OrderEvent> */
final class Order extends EventSourcedAggregateRoot
{
    public bool $placed = false;

    /** @var list<string> */
    public array $lines = [];

    public static function placeNew(TestUlidId $id): self
    {
        $order = new self($id);
        $order->recordThat(new OrderPlaced($id->value()));

        return $order;
    }

    #[Override]
    public function id(): TestUlidId
    {
        /** @var TestUlidId */
        return $this->id;
    }

    public function addLine(string $sku): void
    {
        $this->recordThat(new OrderLineAdded($sku));
    }

    #[Override]
    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof OrderPlaced => $this->placed = true,
            $event instanceof OrderLineAdded => $this->lines[] = $event->sku,
        };
    }
}

interface OrderEvent extends DomainEvent {}

final readonly class OrderPlaced implements OrderEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class OrderLineAdded implements OrderEvent
{
    public function __construct(public string $sku) {}
}
