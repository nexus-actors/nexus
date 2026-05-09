<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event;

use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Override;

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

    #[Override]
    public function appendIfVersion(AggregateStreamId $streamId, int $expectedVersion, StoredEvent ...$events): void
    {
        $key = $streamId->toString();
        $current = count($this->streams[$key] ?? []);

        if ($current !== $expectedVersion) {
            throw new OptimisticLockException(
                $streamId->aggregateClass,
                $streamId->aggregateId,
                $expectedVersion,
                $current,
            );
        }

        foreach ($events as $event) {
            $this->streams[$key][] = $event;
        }
    }

    /** @return iterable<StoredEvent> */
    #[Override]
    public function load(
        AggregateStreamId $streamId,
        int $fromSequenceNr = 0,
        int $toSequenceNr = PHP_INT_MAX,
    ): iterable {
        $stream = $this->streams[$streamId->toString()] ?? [];

        foreach ($stream as $event) {
            $seq = $event->sequenceNr;

            if ($seq >= $fromSequenceNr && $seq <= $toSequenceNr) {
                yield $event;
            }
        }
    }

    #[Override]
    public function deleteUpTo(AggregateStreamId $streamId, int $toSequenceNr): void
    {
        $key = $streamId->toString();
        $stream = $this->streams[$key] ?? [];
        $this->streams[$key] = array_values(array_filter(
            $stream,
            static fn(StoredEvent $e): bool => $e->sequenceNr > $toSequenceNr,
        ));
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
