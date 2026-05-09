<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event;

use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreAppend;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreDelete;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreLoad;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreAppend;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreDelete;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreLoad;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\EventStoreAppendFailed;
use Monadial\Nexus\Ddd\Aggregate\Hook\NullEventDispatcher;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * @psalm-api
 *
 * In-memory implementation. TESTS-ONLY (and single-process Fiber-only).
 * Uses an associative array keyed by AggregateStreamId.toString() with a
 * list of StoredEvents; the count of events is the "current version" for
 * appendIfVersion's check.
 */
final class InMemoryVersionedEventStore implements VersionedEventStore
{
    /** @var array<string, list<StoredEvent>> */
    private array $streams = [];

    public function __construct(private readonly EventDispatcherInterface $events = new NullEventDispatcher()) {}

    #[Override]
    public function appendIfVersion(AggregateStreamId $streamId, int $expectedVersion, StoredEvent ...$events): void
    {
        $envelopes = array_values($events);
        $this->events->dispatch(new BeforeEventStoreAppend($streamId, $expectedVersion, $envelopes));

        $key = $streamId->toString();
        $current = count($this->streams[$key] ?? []);

        if ($current !== $expectedVersion) {
            $exception = new OptimisticLockException(
                $streamId->aggregateClass,
                $streamId->aggregateId,
                $expectedVersion,
                $current,
            );
            $this->events->dispatch(new EventStoreAppendFailed($streamId, $expectedVersion, $envelopes, $exception));

            throw $exception;
        }

        try {
            foreach ($envelopes as $event) {
                $this->streams[$key][] = $event;
            }
        } catch (Throwable $e) {
            $this->events->dispatch(new EventStoreAppendFailed($streamId, $expectedVersion, $envelopes, $e));

            throw $e;
        }

        $finalVersion = count($this->streams[$key] ?? []);
        $this->events->dispatch(new AfterEventStoreAppend($streamId, $finalVersion, $envelopes));
    }

    /** @return iterable<StoredEvent> */
    #[Override]
    public function load(
        AggregateStreamId $streamId,
        int $fromSequenceNr = 0,
        int $toSequenceNr = PHP_INT_MAX,
    ): iterable {
        $this->events->dispatch(new BeforeEventStoreLoad($streamId, $fromSequenceNr, $toSequenceNr));

        $stream = $this->streams[$streamId->toString()] ?? [];
        $loaded = [];

        foreach ($stream as $event) {
            $seq = $event->sequenceNr;

            if ($seq >= $fromSequenceNr && $seq <= $toSequenceNr) {
                $loaded[] = $event;
            }
        }

        $this->events->dispatch(new AfterEventStoreLoad($streamId, $fromSequenceNr, $toSequenceNr, $loaded));

        yield from $loaded;
    }

    #[Override]
    public function deleteUpTo(AggregateStreamId $streamId, int $toSequenceNr): void
    {
        $this->events->dispatch(new BeforeEventStoreDelete($streamId, $toSequenceNr));

        $key = $streamId->toString();
        $stream = $this->streams[$key] ?? [];
        $this->streams[$key] = array_values(array_filter(
            $stream,
            static fn(StoredEvent $e): bool => $e->sequenceNr > $toSequenceNr,
        ));

        $this->events->dispatch(new AfterEventStoreDelete($streamId, $toSequenceNr));
    }

    #[Override]
    public function highestSequenceNr(AggregateStreamId $streamId): int
    {
        $stream = $this->streams[$streamId->toString()] ?? [];
        /** @var StoredEvent|null $last — Psalm CallMap signature for array_last is mixed regardless of input element type */
        $last = array_last($stream);

        if ($last === null) {
            return 0;
        }

        return $last->sequenceNr;
    }
}
