<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Repository;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Strategy\PersistenceStrategy;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

/**
 * @psalm-api
 *
 * @template T of AggregateRoot
 * @implements AggregateRepository<T>
 *
 * Generic aggregate repository — the default impl every domain uses.
 * Wraps a `PersistenceStrategy` (which is itself a composite over
 * EventSourcedPersister + StatefulPersister, dispatched at the
 * composite seam by aggregate kind).
 *
 * Subclass to add domain-specific bulk read methods (`OrderRepository::inBatch`
 * for read-only command-side bulk processing). Bulk WRITE methods are
 * forbidden — that's a query in disguise; route through QueryBus +
 * projection tables instead.
 *
 * Note on one-aggregate-per-transaction (v6 §9.1.0.2): this repository
 * does NOT enforce that rule. Enforcement lives in bus middleware in a
 * future package. The MultiAggregateTransactionException class exists
 * (Phase 2) for that bus middleware's use; nothing in this class
 * throws it.
 */
final readonly class GenericAggregateRepository implements AggregateRepository
{
    /** @param class-string<T> $aggregateClass */
    public function __construct(private string $aggregateClass, private PersistenceStrategy $strategy) {}

    /**
     * @return Option<T>
     *
     * PersistenceStrategy::load is `@template T of AggregateRoot` taking
     * `class-string<T>` and returning `Option<T>`. Passing
     * `class-string<RepoT>` (where RepoT extends AggregateRoot) binds
     * the strategy's T to RepoT, so Option<RepoT> flows back directly.
     */
    #[Override]
    public function find(Identifier $id): Option
    {
        return $this->strategy->load($this->aggregateClass, $id);
    }

    /**
     * Upsert — delegates to the strategy, which routes:
     *   - expectedVersion === 0 (brand-new aggregate id) collision → AggregateAlreadyExistsException
     *   - expectedVersion > 0 (loaded aggregate's version no longer current) → OptimisticLockException
     *
     * The expectedVersion arithmetic happens inside the strategy via
     * `aggregate.version() - count(recordedEvents)` — fresh aggregates
     * yield expectedVersion=0 because version equals event count.
     *
     * @param T $aggregate
     */
    #[Override]
    public function save(AggregateRoot $aggregate): void
    {
        $this->strategy->persist($aggregate);
    }
}
