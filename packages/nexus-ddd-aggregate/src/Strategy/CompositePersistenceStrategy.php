<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy;

use Fp\Functional\Option\Option;
use LogicException;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

use function is_subclass_of;
use function sprintf;

/**
 * @psalm-api
 *
 * Dispatches persistence operations to the correct kind-specific
 * persister via `instanceof` checks ONCE at the public seam. Domain
 * code (handlers, aggregates) never sees this branching.
 *
 * Composes `EventSourcedPersister` + `StatefulPersister`; both injected.
 * Aggregates that are neither (e.g., a hypothetical third base class)
 * raise `LogicException` — this is a programmer error, not a domain
 * fact.
 *
 * `StatefulAggregateRoot` is checked FIRST in both `persist()` and
 * `load()` for symmetry: although `StatefulAggregateRoot` does not
 * extend `EventSourcedAggregateRoot` today (so the order is not
 * strictly load-bearing), keeping the order consistent across both
 * methods makes the dispatch table easier to read and survives a
 * future refactor that introduces a shared abstract base.
 */
final readonly class CompositePersistenceStrategy implements PersistenceStrategy
{
    public function __construct(private EventSourcedPersister $eventSourced, private StatefulPersister $stateful) {}

    #[Override]
    public function persist(AggregateRoot $entity): void
    {
        match (true) {
            $entity instanceof StatefulAggregateRoot => $this->stateful->persist($entity),
            $entity instanceof EventSourcedAggregateRoot => $this->eventSourced->persist($entity),
            default => throw new LogicException(sprintf(
                '%s is neither EventSourcedAggregateRoot nor StatefulAggregateRoot — cannot persist.',
                $entity::class,
            )),
        };
    }

    /**
     * @template T of AggregateRoot
     *
     * @param class-string<T> $entityClass
     *
     * @return Option<T>
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement, DocblockTypeContradiction
     *                 — `is_subclass_of` narrows `$entityClass` at runtime
     *                 to one of the two kind-specific class-strings, but
     *                 Psalm cannot relate `Option<EventSourcedAggregateRoot>`
     *                 / `Option<StatefulAggregateRoot>` returned by the
     *                 inner persisters back to `Option<T>` in the outer
     *                 template (T is invariant). The casts are sound: each
     *                 branch guarantees the inner persister was given a
     *                 `class-string<T>`, so its `Option` values are `T`.
     */
    #[Override]
    public function load(string $entityClass, Identifier $id): Option
    {
        if (is_subclass_of($entityClass, StatefulAggregateRoot::class)) {
            return $this->stateful->load($entityClass, $id);
        }

        if (is_subclass_of($entityClass, EventSourcedAggregateRoot::class)) {
            return $this->eventSourced->load($entityClass, $id);
        }

        throw new LogicException(sprintf(
            '%s is neither EventSourcedAggregateRoot nor StatefulAggregateRoot — cannot load.',
            $entityClass,
        ));
    }
}
