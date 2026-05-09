<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Snapshot;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotDelete;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotLoad;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotSave;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotDelete;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotLoad;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotSave;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;
use Monadial\Nexus\Ddd\Aggregate\Tests\Support\CapturingEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(InMemorySnapshotStore::class)]
final class InMemorySnapshotStoreDispatcherTest extends TestCase
{
    #[Test]
    public function saveDispatchesBeforeAndAfterEvents(): void
    {
        $dispatcher = new CapturingEventDispatcher();
        $store = new InMemorySnapshotStore($dispatcher);
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $snapshot = $this->buildSnapshot($streamId, 5);

        $store->save($snapshot);

        self::assertCount(2, $dispatcher->captured);
        self::assertInstanceOf(BeforeSnapshotSave::class, $dispatcher->captured[0]);
        self::assertInstanceOf(AfterSnapshotSave::class, $dispatcher->captured[1]);
        self::assertSame($snapshot, $dispatcher->captured[0]->snapshot);
        self::assertSame($snapshot, $dispatcher->captured[1]->snapshot);
    }

    #[Test]
    public function loadDispatchesBeforeAndAfterEventsWithNoneOnMiss(): void
    {
        $dispatcher = new CapturingEventDispatcher();
        $store = new InMemorySnapshotStore($dispatcher);
        $streamId = new AggregateStreamId('App\\Order', 'order-1');

        $result = $store->load($streamId);

        self::assertCount(2, $dispatcher->captured);
        self::assertInstanceOf(BeforeSnapshotLoad::class, $dispatcher->captured[0]);
        self::assertInstanceOf(AfterSnapshotLoad::class, $dispatcher->captured[1]);
        self::assertTrue($dispatcher->captured[1]->snapshot->isNone());
        self::assertTrue($result->isNone());
    }

    #[Test]
    public function loadDispatchesAfterEventCarryingSomeOnHit(): void
    {
        $dispatcher = new CapturingEventDispatcher();
        $store = new InMemorySnapshotStore($dispatcher);
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $snapshot = $this->buildSnapshot($streamId, 5);
        $store->save($snapshot);
        $dispatcher->captured = [];

        $store->load($streamId);

        self::assertCount(2, $dispatcher->captured);
        self::assertInstanceOf(AfterSnapshotLoad::class, $dispatcher->captured[1]);
        self::assertTrue($dispatcher->captured[1]->snapshot->isSome());
        self::assertSame($snapshot, $dispatcher->captured[1]->snapshot->get());
    }

    #[Test]
    public function deleteDispatchesBeforeAndAfterEvents(): void
    {
        $dispatcher = new CapturingEventDispatcher();
        $store = new InMemorySnapshotStore($dispatcher);
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $store->save($this->buildSnapshot($streamId, 5));
        $dispatcher->captured = [];

        $store->delete($streamId, 5);

        self::assertCount(2, $dispatcher->captured);
        self::assertInstanceOf(BeforeSnapshotDelete::class, $dispatcher->captured[0]);
        self::assertInstanceOf(AfterSnapshotDelete::class, $dispatcher->captured[1]);
        self::assertSame(5, $dispatcher->captured[0]->upToSequenceNr);
        self::assertSame(5, $dispatcher->captured[1]->upToSequenceNr);
    }

    private function buildSnapshot(AggregateStreamId $streamId, int $sequenceNr): Snapshot
    {
        return new Snapshot(
            $streamId,
            $sequenceNr,
            new stdClass(),
            stdClass::class,
            1,
            new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        );
    }
}
