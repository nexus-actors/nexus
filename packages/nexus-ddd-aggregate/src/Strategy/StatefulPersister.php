<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;

/**
 * @psalm-api
 *
 * Persister specialised for state-stored aggregates. Composite persistence
 * strategies dispatch by `instanceof` and forward to this narrower interface
 * for `StatefulAggregateRoot` subclasses; event-sourced aggregates take the
 * sibling `EventSourcedPersister` path.
 *
 * Stateful persistence stores the aggregate's full serialised state and
 * replaces it on update — there is no event stream; the latest state IS
 * the aggregate. OCC is enforced via a per-row `version` slot:
 *   - brand-new aggregate (`expectedVersion === 0`) takes the INSERT path;
 *     a uniqueness collision raises `AggregateAlreadyExistsException`.
 *   - existing aggregate (`expectedVersion > 0`) takes the
 *     UPDATE-WHERE-version path; a mismatch raises `OptimisticLockException`.
 */
interface StatefulPersister
{
    /**
     * @template T of StatefulAggregateRoot
     *
     * @param class-string<T> $entityClass
     *
     * @return Option<T>
     */
    public function load(string $entityClass, Identifier $id): Option;

    /**
     * @throws OptimisticLockException when a non-creation update is racing
     *         another writer that committed first.
     * @throws AggregateAlreadyExistsException when a brand-new aggregate
     *         (`expectedVersion === 0`) collides with an aggregate of the
     *         same id already persisted.
     */
    public function persist(StatefulAggregateRoot $entity): void;
}
