<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Repository;

use Fp\Functional\Option\Option;
use LogicException;
use Monadial\Nexus\Ddd\Aggregate\Strategy\PersistenceStrategy;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

use function sprintf;

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
     * Persist a brand-new aggregate. Asserts version=0; otherwise this
     * is a programmer error (caller should have used save() for a
     * loaded-and-modified aggregate).
     *
     * @param T $aggregate
     */
    #[Override]
    public function add(AggregateRoot $aggregate): void
    {
        if ($aggregate->version() !== 0) {
            throw new LogicException(sprintf(
                'add() invoked on an aggregate with version %d; add() requires version 0. Use save() for previously-loaded aggregates.',
                $aggregate->version(),
            ));
        }

        $this->strategy->persist($aggregate);
    }

    /**
     * Upsert — delegates straight to the strategy, which routes
     * version=0 to AggregateAlreadyExistsException on collision and
     * version>0 to OptimisticLockException on stale-write.
     *
     * @param T $aggregate
     */
    #[Override]
    public function save(AggregateRoot $aggregate): void
    {
        $this->strategy->persist($aggregate);
    }
}
