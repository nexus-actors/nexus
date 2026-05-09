<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event;

use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;

/**
 * @psalm-api
 *
 * Versioned-append event store. DDD-owned, standalone — does NOT
 * extend any other event-store contract. The DDD packages own this
 * abstraction so they can be extracted to a separate project.
 *
 * Atomically appends events iff the current highest sequence number
 * for `$streamId` equals `$expectedVersion`; mismatch raises
 * `OptimisticLockException` and no events are persisted.
 *
 * MUST implementation rules (per umbrella spec v6 §9.2.1):
 *   - Version check via UNIQUE(stream_id, sequence_nr) — read-then-write
 *     races under all isolation levels and is forbidden.
 *   - Isolation level: READ COMMITTED. SQLSTATE 40001 (serialization
 *     failure) and InnoDB deadlocks (1213) MUST be mapped to
 *     OptimisticLockException for uniformity.
 *   - `appendIfVersion` participates in the caller's open transaction.
 */
interface VersionedEventStore
{
    /**
     * @throws OptimisticLockException when expectedVersion does not match
     *         the current highest sequence number.
     */
    public function appendIfVersion(AggregateStreamId $streamId, int $expectedVersion, StoredEvent ...$events): void;

    /** @return iterable<StoredEvent> */
    public function load(
        AggregateStreamId $streamId,
        int $fromSequenceNr = 0,
        int $toSequenceNr = PHP_INT_MAX,
    ): iterable;

    public function deleteUpTo(AggregateStreamId $streamId, int $toSequenceNr): void;

    public function highestSequenceNr(AggregateStreamId $streamId): int;
}
