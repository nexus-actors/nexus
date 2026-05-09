<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;

/**
 * @psalm-api
 *
 * Public seam the `AggregateRepository` sees. Internally, persistence
 * splits by aggregate kind: `EventSourcedPersister` for event-sourced
 * aggregates, `StatefulPersister` for state-stored aggregates.
 * `CompositePersistenceStrategy` dispatches by `instanceof` ONCE at
 * this seam — handler/aggregate code never branches on aggregate kind.
 *
 * Parameter type is `AggregateRoot` (the common supertype) — NOT
 * `EventSourceable`. Per spec v6 §9.2, `StatefulAggregateRoot` does
 * not implement `EventSourceable` (its docblock explicitly says so),
 * so narrowing to that supertype would exclude stateful aggregates.
 * Process-manager persistence (PMs are `Identifiable + EventSourceable`
 * but not `AggregateRoot`) is out of scope for this package.
 */
interface PersistenceStrategy
{
    /**
     * @template T of AggregateRoot
     *
     * @param class-string<T> $entityClass
     *
     * @return Option<T>
     */
    public function load(string $entityClass, Identifier $id): Option;

    /**
     * @throws OptimisticLockException when a non-creation persist is racing
     *         another writer that committed first.
     * @throws AggregateAlreadyExistsException when a brand-new aggregate
     *         (`expectedVersion === 0`) collides with an aggregate of the
     *         same id already persisted.
     */
    public function persist(AggregateRoot $entity): void;
}
