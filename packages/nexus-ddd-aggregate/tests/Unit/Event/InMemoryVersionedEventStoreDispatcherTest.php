<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreAppend;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreDelete;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreLoad;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreAppend;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreDelete;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreLoad;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\EventStoreAppendFailed;
use Monadial\Nexus\Ddd\Aggregate\Event\InMemoryVersionedEventStore;
use Monadial\Nexus\Ddd\Aggregate\Event\StoredEvent;
use Monadial\Nexus\Ddd\Aggregate\Tests\Support\CapturingEventDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryVersionedEventStore::class)]
final class InMemoryVersionedEventStoreDispatcherTest extends TestCase
{
    #[Test]
    public function appendIfVersionDispatchesBeforeAndAfterEvents(): void
    {
        $dispatcher = new CapturingEventDispatcher();
        $store = new InMemoryVersionedEventStore($dispatcher);
        $streamId = new AggregateStreamId('App\\Order', 'order-1');

        $store->appendIfVersion($streamId, 0, $this->buildStoredEvent($streamId, 1));

        self::assertCount(2, $dispatcher->captured);
        self::assertInstanceOf(BeforeEventStoreAppend::class, $dispatcher->captured[0]);
        self::assertInstanceOf(AfterEventStoreAppend::class, $dispatcher->captured[1]);
        self::assertSame($streamId, $dispatcher->captured[0]->streamId);
        self::assertSame(0, $dispatcher->captured[0]->expectedVersion);
        self::assertSame($streamId, $dispatcher->captured[1]->streamId);
        self::assertSame(1, $dispatcher->captured[1]->finalVersion);
    }

    #[Test]
    public function appendIfVersionDispatchesFailedEventOnOptimisticLockException(): void
    {
        $dispatcher = new CapturingEventDispatcher();
        $store = new InMemoryVersionedEventStore($dispatcher);
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $store->appendIfVersion($streamId, 0, $this->buildStoredEvent($streamId, 1));
        $dispatcher->captured = [];

        try {
            $store->appendIfVersion($streamId, 0, $this->buildStoredEvent($streamId, 1));
            self::fail('Expected OptimisticLockException');
        } catch (OptimisticLockException) {
        }

        self::assertCount(2, $dispatcher->captured);
        self::assertInstanceOf(BeforeEventStoreAppend::class, $dispatcher->captured[0]);
        self::assertInstanceOf(EventStoreAppendFailed::class, $dispatcher->captured[1]);
        self::assertInstanceOf(OptimisticLockException::class, $dispatcher->captured[1]->exception);
    }

    #[Test]
    public function loadDispatchesBeforeAndAfterEvents(): void
    {
        $dispatcher = new CapturingEventDispatcher();
        $store = new InMemoryVersionedEventStore($dispatcher);
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $event = $this->buildStoredEvent($streamId, 1);
        $store->appendIfVersion($streamId, 0, $event);
        $dispatcher->captured = [];

        $loaded = iterator_to_array($store->load($streamId), false);

        self::assertCount(2, $dispatcher->captured);
        self::assertInstanceOf(BeforeEventStoreLoad::class, $dispatcher->captured[0]);
        self::assertInstanceOf(AfterEventStoreLoad::class, $dispatcher->captured[1]);
        self::assertSame($streamId, $dispatcher->captured[1]->streamId);
        self::assertSame([$event], $dispatcher->captured[1]->events);
        self::assertSame([$event], $loaded);
    }

    #[Test]
    public function deleteUpToDispatchesBeforeAndAfterEvents(): void
    {
        $dispatcher = new CapturingEventDispatcher();
        $store = new InMemoryVersionedEventStore($dispatcher);
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $store->appendIfVersion($streamId, 0, $this->buildStoredEvent($streamId, 1));
        $dispatcher->captured = [];

        $store->deleteUpTo($streamId, 1);

        self::assertCount(2, $dispatcher->captured);
        self::assertInstanceOf(BeforeEventStoreDelete::class, $dispatcher->captured[0]);
        self::assertInstanceOf(AfterEventStoreDelete::class, $dispatcher->captured[1]);
        self::assertSame(1, $dispatcher->captured[0]->toSequenceNr);
        self::assertSame(1, $dispatcher->captured[1]->toSequenceNr);
    }

    private function buildStoredEvent(AggregateStreamId $streamId, int $sequenceNr): StoredEvent
    {
        $event = new InMemoryVersionedEventStoreDispatcherTestEvent();

        return new StoredEvent(
            $streamId,
            $sequenceNr,
            $event,
            $event::class,
            new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        );
    }
}

final readonly class InMemoryVersionedEventStoreDispatcherTestEvent implements DomainEvent {}
