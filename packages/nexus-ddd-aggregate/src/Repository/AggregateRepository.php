<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Repository;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;

/**
 * @psalm-api
 *
 * @template T of AggregateRoot
 *
 * The public face of the persistence layer. Domain handlers depend
 * ONLY on this interface; the strategy/persister layer is invisible
 * to them.
 *
 * Three methods, mapping to spec v6 §9.1:
 *   - `find()`: command-side loader. NEVER for queries — read-side
 *     queries go through QueryBus + projection tables.
 *   - `add()`: persist a brand-new aggregate. Asserts version=0.
 *     Uniqueness collision raises AggregateAlreadyExistsException
 *     (terminal — bus middleware does NOT retry).
 *   - `save()`: upsert. v=0 behaves like add(); v>0 versioned-append
 *     raises OptimisticLockException on stale-write (retried by
 *     bus middleware).
 *
 * NOT exposed: `remove()` / `delete()`. Aggregate retirement is a
 * domain event (e.g., `OrderArchived`); retention/GC is an
 * infrastructure concern handled by RetentionPolicy outside the
 * Repository (per v6 §9.1.0.1).
 */
interface AggregateRepository
{
    /**
     * Command-side loader. Returns Option::none() on miss.
     *
     * @return Option<T>
     */
    public function find(Identifier $id): Option;

    /**
     * Persist a brand-new aggregate. Asserts `$aggregate->version() === 0`.
     *
     * @param T $aggregate
     *
     * @throws AggregateAlreadyExistsException uniqueness collision (terminal)
     */
    public function add(AggregateRoot $aggregate): void;

    /**
     * Upsert. v=0 -> behaves like add(); v>0 -> versioned-append.
     *
     * @param T $aggregate
     *
     * @throws OptimisticLockException stale-write on existing aggregate (retryable by bus middleware)
     * @throws AggregateAlreadyExistsException uniqueness collision on creation (terminal)
     */
    public function save(AggregateRoot $aggregate): void;
}
