<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Snapshot;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(InMemorySnapshotStore::class)]
final class InMemorySnapshotStoreTest extends TestCase
{
    #[Test]
    public function loadReturnsNoneOnMiss(): void
    {
        $store = new InMemorySnapshotStore();

        self::assertTrue(
            $store->load(new AggregateStreamId('App\\Order', 'order-1'))->isNone(),
        );
    }

    #[Test]
    public function saveThenLoadReturnsSomeWithStoredSnapshot(): void
    {
        $store = new InMemorySnapshotStore();
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $snapshot = $this->buildSnapshot($streamId, 5);

        $store->save($snapshot);
        $loaded = $store->load($streamId);

        self::assertTrue($loaded->isSome());
        self::assertSame($snapshot, $loaded->get());
    }

    #[Test]
    public function saveOverwritesPreviousSnapshotForSameStream(): void
    {
        $store = new InMemorySnapshotStore();
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $first = $this->buildSnapshot($streamId, 5);
        $second = $this->buildSnapshot($streamId, 9);

        $store->save($first);
        $store->save($second);
        $loaded = $store->load($streamId);

        self::assertTrue($loaded->isSome());
        self::assertSame($second, $loaded->get());
    }

    #[Test]
    public function deleteRemovesSnapshotWhenSequenceIsAtOrBelowThreshold(): void
    {
        $store = new InMemorySnapshotStore();
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $store->save($this->buildSnapshot($streamId, 5));

        $store->delete($streamId, 5);

        self::assertTrue($store->load($streamId)->isNone());
    }

    #[Test]
    public function deleteIsNoopWhenSnapshotSequenceExceedsThreshold(): void
    {
        $store = new InMemorySnapshotStore();
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $snapshot = $this->buildSnapshot($streamId, 10);
        $store->save($snapshot);

        $store->delete($streamId, 5);

        $loaded = $store->load($streamId);
        self::assertTrue($loaded->isSome());
        self::assertSame($snapshot, $loaded->get());
    }

    #[Test]
    public function deleteIsNoopWhenStreamHasNoSnapshot(): void
    {
        $store = new InMemorySnapshotStore();
        $streamId = new AggregateStreamId('App\\Order', 'absent');

        $store->delete($streamId, 999);

        self::assertTrue($store->load($streamId)->isNone());
    }

    #[Test]
    public function streamsAreIsolatedByStreamId(): void
    {
        $store = new InMemorySnapshotStore();
        $a = new AggregateStreamId('App\\Order', 'order-1');
        $b = new AggregateStreamId('App\\Order', 'order-2');
        $snapshotA = $this->buildSnapshot($a, 1);

        $store->save($snapshotA);

        self::assertTrue($store->load($a)->isSome());
        self::assertTrue($store->load($b)->isNone());
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
