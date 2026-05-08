<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event;

use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\PersistenceId;
use Override;

/**
 * @psalm-api
 *
 * In-memory implementation. TESTS-ONLY (and single-process Fiber-only).
 * Uses an associative array keyed by PersistenceId.toString() with a list
 * of EventEnvelopes; the count of events is the "current version" for
 * appendIfVersion's check.
 */
final class InMemoryVersionedEventStore implements VersionedEventStore
{
    /** @var array<string, list<EventEnvelope>> */
    private array $streams = [];

    /**
     * @psalm-suppress ArgumentTypeCoercion PersistenceId::$entityType is string;
     *                 OptimisticLockException::$entityClass is documented as
     *                 class-string (will be tightened to class-string<EventSourceable>
     *                 in Task 12). Higher-layer persisters pass real class-strings;
     *                 this low-level store only has the entityType string from id.
     */
    #[Override]
    public function appendIfVersion(PersistenceId $persistenceId, int $expectedVersion, EventEnvelope ...$events): void
    {
        $key = $persistenceId->toString();
        $current = count($this->streams[$key] ?? []);

        if ($current !== $expectedVersion) {
            throw new OptimisticLockException(
                $persistenceId->entityType,
                $persistenceId->entityId,
                $expectedVersion,
                $current,
            );
        }

        foreach ($events as $event) {
            $this->streams[$key][] = $event;
        }
    }

    #[Override]
    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        $key = $id->toString();

        foreach ($events as $event) {
            $this->streams[$key][] = $event;
        }
    }

    /** @return iterable<EventEnvelope> */
    #[Override]
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        $stream = $this->streams[$id->toString()] ?? [];

        foreach ($stream as $envelope) {
            $seq = $envelope->sequenceNr;

            if ($seq >= $fromSequenceNr && $seq <= $toSequenceNr) {
                yield $envelope;
            }
        }
    }

    #[Override]
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $key = $id->toString();
        $stream = $this->streams[$key] ?? [];
        $this->streams[$key] = array_values(array_filter(
            $stream,
            static fn(EventEnvelope $e): bool => $e->sequenceNr > $toSequenceNr,
        ));
    }

    #[Override]
    public function highestSequenceNr(PersistenceId $id): int
    {
        $stream = $this->streams[$id->toString()] ?? [];
        /** @var EventEnvelope|null $last — Psalm CallMap signature for array_last is mixed regardless of input element type */
        $last = array_last($stream);

        if ($last === null) {
            return 0;
        }

        return $last->sequenceNr;
    }
}
