<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;

/**
 * @psalm-api
 *
 * Persister specialised for event-sourced aggregates. Composite persistence
 * strategies dispatch by `instanceof` and forward to this narrower interface
 * for `EventSourcedAggregateRoot` subclasses; stateful aggregates take the
 * sibling `StatefulPersister` path.
 *
 * Implementations rebuild aggregate state by replaying the persisted event
 * stream (optionally accelerated by a snapshot) and append new events under
 * an OCC-protected `appendIfVersion` call.
 */
interface EventSourcedPersister
{
    /**
     * @template T of EventSourcedAggregateRoot
     *
     * @param class-string<T> $entityClass
     *
     * @return Option<T>
     */
    public function load(string $entityClass, Identifier $id): Option;

    /**
     * @throws OptimisticLockException when a non-creation append is racing
     *         another writer that committed first.
     * @throws AggregateAlreadyExistsException when a brand-new aggregate
     *         (`expectedVersion === 0`) collides with an aggregate of the
     *         same id already persisted.
     */
    public function persist(EventSourcedAggregateRoot $entity): void;
}
