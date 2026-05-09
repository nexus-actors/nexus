<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Support;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\StoredEvent;
use Monadial\Nexus\Ddd\Aggregate\Event\VersionedEventStore;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

abstract class VersionedEventStoreContractTest extends TestCase
{
    abstract protected function createStore(): VersionedEventStore;

    #[Test]
    public function appendIfVersionWithExpectedZeroSucceedsOnEmptyStream(): void
    {
        $store = $this->createStore();
        $id = new AggregateStreamId('Order', 'order-1');
        $store->appendIfVersion($id, 0, $this->buildStoredEvent(1, new VersionedEventStoreContractTestEvent()));

        self::assertSame(1, $store->highestSequenceNr($id));
    }

    #[Test]
    public function appendIfVersionWithMatchingExpectedSucceeds(): void
    {
        $store = $this->createStore();
        $id = new AggregateStreamId('Order', 'order-1');
        $store->appendIfVersion($id, 0, $this->buildStoredEvent(1, new VersionedEventStoreContractTestEvent()));
        $store->appendIfVersion($id, 1, $this->buildStoredEvent(2, new VersionedEventStoreContractTestEvent()));

        self::assertSame(2, $store->highestSequenceNr($id));
    }

    #[Test]
    public function appendIfVersionWithStaleExpectedThrowsOptimisticLockException(): void
    {
        $store = $this->createStore();
        $id = new AggregateStreamId('Order', 'order-1');
        $store->appendIfVersion($id, 0, $this->buildStoredEvent(1, new VersionedEventStoreContractTestEvent()));

        $this->expectException(OptimisticLockException::class);
        $store->appendIfVersion($id, 0, $this->buildStoredEvent(1, new VersionedEventStoreContractTestEvent()));
    }

    #[Test]
    public function appendIfVersionWithFutureExpectedThrowsOptimisticLockException(): void
    {
        $store = $this->createStore();
        $id = new AggregateStreamId('Order', 'order-1');

        $this->expectException(OptimisticLockException::class);
        $store->appendIfVersion($id, 5, $this->buildStoredEvent(6, new VersionedEventStoreContractTestEvent()));
    }

    #[Test]
    public function appendIfVersionMultipleEventsAppliedAtomically(): void
    {
        $store = $this->createStore();
        $id = new AggregateStreamId('Order', 'order-1');
        $store->appendIfVersion(
            $id,
            0,
            $this->buildStoredEvent(1, new VersionedEventStoreContractTestEvent()),
            $this->buildStoredEvent(2, new VersionedEventStoreContractTestEvent()),
            $this->buildStoredEvent(3, new VersionedEventStoreContractTestEvent()),
        );

        self::assertSame(3, $store->highestSequenceNr($id));
    }

    #[Test]
    public function loadReturnsEventsInSequenceOrder(): void
    {
        $store = $this->createStore();
        $id = new AggregateStreamId('Order', 'order-1');
        $e1 = $this->buildStoredEvent(1, new VersionedEventStoreContractTestEvent());
        $e2 = $this->buildStoredEvent(2, new VersionedEventStoreContractTestEvent());
        $store->appendIfVersion($id, 0, $e1, $e2);
        $loaded = iterator_to_array($store->load($id), false);

        self::assertSame([$e1, $e2], $loaded);
    }

    #[Test]
    public function loadWithSequenceRangeReturnsSubrange(): void
    {
        $store = $this->createStore();
        $id = new AggregateStreamId('Order', 'order-1');
        $events = [
            $this->buildStoredEvent(1, new VersionedEventStoreContractTestEvent()),
            $this->buildStoredEvent(2, new VersionedEventStoreContractTestEvent()),
            $this->buildStoredEvent(3, new VersionedEventStoreContractTestEvent()),
        ];
        $store->appendIfVersion($id, 0, ...$events);
        $sub = iterator_to_array($store->load($id, 2, 3), false);

        self::assertSame([$events[1], $events[2]], $sub);
    }

    #[Test]
    public function deleteUpToRemovesEventsThroughGivenSequence(): void
    {
        $store = $this->createStore();
        $id = new AggregateStreamId('Order', 'order-1');
        $store->appendIfVersion(
            $id,
            0,
            $this->buildStoredEvent(1, new VersionedEventStoreContractTestEvent()),
            $this->buildStoredEvent(2, new VersionedEventStoreContractTestEvent()),
            $this->buildStoredEvent(3, new VersionedEventStoreContractTestEvent()),
        );
        $store->deleteUpTo($id, 2);
        $remaining = iterator_to_array($store->load($id), false);

        self::assertCount(1, $remaining);
    }

    #[Test]
    public function highestSequenceNrIsZeroForEmptyStream(): void
    {
        self::assertSame(
            0,
            $this->createStore()->highestSequenceNr(new AggregateStreamId('Order', 'absent')),
        );
    }

    protected function buildStoredEvent(int $sequenceNr, DomainEvent $event): StoredEvent
    {
        return new StoredEvent(
            new AggregateStreamId('Order', 'order-1'),
            $sequenceNr,
            $event,
            $event::class,
            new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        );
    }
}

final readonly class VersionedEventStoreContractTestEvent implements DomainEvent {}
