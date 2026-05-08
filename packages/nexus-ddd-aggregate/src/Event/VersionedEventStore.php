<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event;

use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * @psalm-api
 *
 * Versioned-append event store. Atomically appends events iff the current
 * highest sequence number for `$persistenceId` equals `$expectedVersion`;
 * mismatch raises `OptimisticLockException` and no events are persisted.
 *
 * MUST implementation rules (per umbrella spec v6 §9.2.1):
 *   - Version check via UNIQUE(aggregate_id, aggregate_version) — read-then-write
 *     across two queries races under all isolation levels and is forbidden.
 *   - Isolation level: READ COMMITTED. SQLSTATE 40001 (serialization failure)
 *     and InnoDB deadlocks (1213) MUST be mapped to OptimisticLockException
 *     for uniformity.
 *   - `appendIfVersion` participates in the caller's open transaction.
 *     The outbox INSERT (when used) shares the same transaction.
 *
 * Why a subinterface, not modifying EventStore: nexus-persistence's
 * EventStore is consumed by actor-based ES which is single-writer and
 * doesn't need conditional append. Forcing all impls to support
 * appendIfVersion would burden actor-only backends.
 */
interface VersionedEventStore extends EventStore
{
    /**
     * @throws OptimisticLockException when expectedVersion does not match
     *         the current highest sequence number.
     */
    public function appendIfVersion(
        PersistenceId $persistenceId,
        int $expectedVersion,
        EventEnvelope ...$events,
    ): void;
}
