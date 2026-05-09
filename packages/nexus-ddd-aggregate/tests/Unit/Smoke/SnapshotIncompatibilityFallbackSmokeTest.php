<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\InMemoryVersionedEventStore;
use Monadial\Nexus\Ddd\Aggregate\Event\Stream\SingleStreamStrategy;
use Monadial\Nexus\Ddd\Aggregate\Repository\GenericAggregateRepository;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;
use Monadial\Nexus\Ddd\Aggregate\Strategy\CompositePersistenceStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\EventSourcingStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\NeverSnapshot;
use Monadial\Nexus\Ddd\Aggregate\Strategy\Stateful\InMemoryStatefulStrategy;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\CustomerId;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\Order;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\OrderId;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\OrderLine;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\OrderLines;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\SmokeFixedClock;
use Monadial\Nexus\Ddd\Aggregate\Versioning\DefaultUpcasterPipeline;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionObject;
use stdClass;
use Symfony\Component\Uid\Ulid;

/**
 * End-to-end smoke for the snapshot-incompatibility fallback path
 * (per umbrella spec v6 §10.3.1). Persists an order via the strategy,
 * then bypasses the strategy to write a snapshot whose `stateType` no
 * longer matches the aggregate class — this is what an old, retired
 * snapshot would look like after a rename or removal of the aggregate.
 *
 * The next `find()` must NOT throw: the strategy detects the
 * incompatibility, logs a warning (silenced via NullLogger here),
 * and falls back to a full replay from the event store. The
 * reconstructed state must match the actual recorded events — the
 * stale snapshot's `stdClass` payload must NOT leak through.
 */
#[CoversNothing]
final class SnapshotIncompatibilityFallbackSmokeTest extends TestCase
{
    #[Test]
    public function loadFallsBackToFullReplayWhenSnapshotStateTypeDoesNotMatch(): void
    {
        $eventStore = new InMemoryVersionedEventStore();
        $snapshotStore = new InMemorySnapshotStore();
        $clock = new SmokeFixedClock(new DateTimeImmutable('2026-05-08T12:00:00+00:00'));

        $repository = new GenericAggregateRepository(
            Order::class,
            new CompositePersistenceStrategy(
                new EventSourcingStrategy(
                    $eventStore,
                    $snapshotStore,
                    new DefaultUpcasterPipeline([]),
                    new SingleStreamStrategy(),
                    new NeverSnapshot(),
                    $clock,
                    new NullLogger(),
                ),
                new InMemoryStatefulStrategy(),
            ),
        );

        $orderId = new OrderId(new Ulid()->toBase32());
        $original = Order::placeNew($orderId, new CustomerId(new Ulid()->toBase32()), new OrderLines([]));
        $original->addLine(new OrderLine('apple', 1, 100));
        $repository->save($original);

        $snapshotStore->save(new Snapshot(
            AggregateStreamId::for(Order::class, $orderId),
            sequenceNr: 1,
            state: new stdClass(),
            stateType: 'SomeOtherClass\\ThatNoLongerExists',
            stateVersion: 999,
            occurredAt: new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        ));

        $reloaded = $repository->find($orderId)->getUnsafe();

        self::assertNotNull($reloaded);
        self::assertSame(2, $reloaded->version());

        $lines = self::readLines($reloaded);
        self::assertSame(1, $lines->count());
        self::assertSame('apple', $lines->lines[0]->name);
    }

    private static function readLines(Order $order): OrderLines
    {
        $field = new ReflectionObject($order)->getProperty('lines');
        $value = $field->getValue($order);

        self::assertInstanceOf(OrderLines::class, $value);

        return $value;
    }
}
