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
 * Two methods:
 *   - `find()`: command-side loader. NEVER for queries — read-side
 *     queries go through QueryBus + projection tables.
 *   - `save()`: upsert. The strategy classifies the failure mode:
 *     uniqueness collision (brand-new aggregate id collides) raises
 *     `AggregateAlreadyExistsException` (terminal — bus middleware
 *     does NOT retry); versioned-append mismatch (concurrent writer
 *     advanced the version after we loaded) raises
 *     `OptimisticLockException` (retried by bus middleware).
 *
 * Earlier drafts split `add()` from `save()` to surface call-site
 * intent ("I'm creating" vs "I'm updating"). The split was dropped
 * because it doesn't compose with the canonical event-sourced
 * static-factory pattern: `Order::placeNew(...)` calls `recordThat(new
 * OrderPlaced(...))` which advances `version()` to 1 before the caller
 * can invoke any repository method, making `version() === 0` an
 * unreachable check for ES aggregates. The strategy's
 * `expectedVersion = version - count(events)` math correctly classifies
 * both cases without the API split.
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
     * Upsert. Strategy classifies the failure path:
     *   - new-aggregate-id collision → AggregateAlreadyExistsException
     *   - stale-write on loaded aggregate → OptimisticLockException
     *
     * @param T $aggregate
     *
     * @throws OptimisticLockException stale-write on existing aggregate (retryable by bus middleware)
     * @throws AggregateAlreadyExistsException uniqueness collision on creation (terminal)
     */
    public function save(AggregateRoot $aggregate): void;
}
