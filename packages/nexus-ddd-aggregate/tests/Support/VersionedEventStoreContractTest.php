<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Support;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\VersionedEventStore;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

abstract class VersionedEventStoreContractTest extends TestCase
{
    abstract protected function createStore(): VersionedEventStore;

    #[Test]
    public function appendIfVersionWithExpectedZeroSucceedsOnEmptyStream(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion($id, 0, $this->buildEnvelope(1, new stdClass()));

        self::assertSame(1, $store->highestSequenceNr($id));
    }

    #[Test]
    public function appendIfVersionWithMatchingExpectedSucceeds(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion($id, 0, $this->buildEnvelope(1, new stdClass()));
        $store->appendIfVersion($id, 1, $this->buildEnvelope(2, new stdClass()));

        self::assertSame(2, $store->highestSequenceNr($id));
    }

    #[Test]
    public function appendIfVersionWithStaleExpectedThrowsOptimisticLockException(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion($id, 0, $this->buildEnvelope(1, new stdClass()));

        $this->expectException(OptimisticLockException::class);
        $store->appendIfVersion($id, 0, $this->buildEnvelope(1, new stdClass()));
    }

    #[Test]
    public function appendIfVersionWithFutureExpectedThrowsOptimisticLockException(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');

        $this->expectException(OptimisticLockException::class);
        $store->appendIfVersion($id, 5, $this->buildEnvelope(6, new stdClass()));
    }

    #[Test]
    public function appendIfVersionMultipleEventsAppliedAtomically(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion(
            $id,
            0,
            $this->buildEnvelope(1, new stdClass()),
            $this->buildEnvelope(2, new stdClass()),
            $this->buildEnvelope(3, new stdClass()),
        );

        self::assertSame(3, $store->highestSequenceNr($id));
    }

    #[Test]
    public function loadReturnsEventsInSequenceOrder(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $e1 = $this->buildEnvelope(1, new stdClass());
        $e2 = $this->buildEnvelope(2, new stdClass());
        $store->appendIfVersion($id, 0, $e1, $e2);
        $loaded = iterator_to_array($store->load($id), false);

        self::assertSame([$e1, $e2], $loaded);
    }

    #[Test]
    public function loadWithSequenceRangeReturnsSubrange(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $envelopes = [
            $this->buildEnvelope(1, new stdClass()),
            $this->buildEnvelope(2, new stdClass()),
            $this->buildEnvelope(3, new stdClass()),
        ];
        $store->appendIfVersion($id, 0, ...$envelopes);
        $sub = iterator_to_array($store->load($id, 2, 3), false);

        self::assertSame([$envelopes[1], $envelopes[2]], $sub);
    }

    #[Test]
    public function deleteUpToRemovesEventsThroughGivenSequence(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion(
            $id,
            0,
            $this->buildEnvelope(1, new stdClass()),
            $this->buildEnvelope(2, new stdClass()),
            $this->buildEnvelope(3, new stdClass()),
        );
        $store->deleteUpTo($id, 2);
        $remaining = iterator_to_array($store->load($id), false);

        self::assertCount(1, $remaining);
    }

    #[Test]
    public function highestSequenceNrIsZeroForEmptyStream(): void
    {
        self::assertSame(0, $this->createStore()->highestSequenceNr(PersistenceId::of('Order', 'absent')));
    }

    protected function buildEnvelope(int $sequenceNr, object $event): EventEnvelope
    {
        return new EventEnvelope(
            PersistenceId::of('Order', 'order-1'),
            $sequenceNr,
            $event,
            $event::class,
            new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        );
    }
}
