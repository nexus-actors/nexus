<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Event;

use Monadial\Nexus\Persistence\PersistenceId;

/**
 * Read/write interface for event-sourced event streams.
 *
 * An `EventStore` durably records the ordered sequence of `EventEnvelope` objects
 * that represent every state change a persistent actor has ever made. On actor
 * startup the `PersistenceEngine` calls `load()` to replay events from the
 * last snapshot sequence number forward, reconstructing the actor's current state.
 *
 * Three adapters ship out of the box:
 * - `InMemoryEventStore` — for unit tests; no persistence across process restarts.
 * - `DbalEventStore` (nexus-persistence-dbal) — DBAL-backed, production-ready.
 * - `DoctrineEventStore` (nexus-persistence-doctrine) — Doctrine ORM-backed.
 *
 * Example (wiring a DBAL store):
 * ```php
 * $store = new DbalEventStore($connection, 'nexus_events');
 *
 * EventSourcedBehavior::create($persistenceId, $emptyState, $commandHandler, $eventHandler)
 *     ->withEventStore($store)
 *     ->toBehavior();
 * ```
 *
 * @see EventEnvelope   for the envelope wrapping each persisted event
 * @see PersistenceId   for the unique actor identity used as the stream key
 * @see EventSourcedBehavior for the fluent builder that consumes this interface
 *
 * @psalm-api
 */
interface EventStore
{
    /**
     * Append one or more events to the stream identified by `$id`.
     *
     * Implementations must write all events atomically and assign monotonically
     * increasing sequence numbers. Throws on write failure.
     */
    public function persist(PersistenceId $id, EventEnvelope ...$events): void;

    /**
     * Load events for `$id` within the given sequence number range (inclusive).
     *
     * Defaults to the full stream. The `PersistenceEngine` passes `$fromSequenceNr`
     * as one past the last snapshot sequence number to avoid replaying already-applied events.
     *
     * @return iterable<EventEnvelope>
     */
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable;

    /**
     * Delete all events up to and including `$toSequenceNr` for the given actor.
     *
     * Called by the retention policy after a snapshot is confirmed durable.
     * Implementations should be idempotent — deleting already-deleted events is a no-op.
     */
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void;

    /**
     * Return the highest sequence number written for `$id`, or 0 if no events exist.
     *
     * Used by the engine to detect writer conflicts and to set the starting point
     * for the next `persist()` call.
     */
    public function highestSequenceNr(PersistenceId $id): int;
}
