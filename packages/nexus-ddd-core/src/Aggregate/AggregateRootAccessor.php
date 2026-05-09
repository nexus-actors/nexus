<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use LogicException;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

/**
 * @psalm-api
 *
 * Friend-class accessor for repository-level access to AggregateRoot's
 * framework hooks (`pullRecordedEvents`, `version`, `rehydrateVersion`).
 * PHP has no native friend-class mechanism; this class extends
 * `AggregateRoot` to gain protected-access rights via PHP's "any
 * instance of the same class hierarchy can read/call protected members
 * of any other instance" rule.
 *
 * Single concrete class — works for every `AggregateRoot` subclass
 * (event-sourced AND stateful) since these hooks live on the parent.
 * Stateless; share one instance across all repositories.
 *
 * For event-sourced-specific hooks (`replay`), use
 * `EventSourcedAggregateRootAccessor` — a separate accessor that extends
 * `EventSourcedAggregateRoot` directly. The two accessors are parallel
 * (not parent/child) because PHP's protected-access rule requires the
 * calling context to be a subclass of the declaring class — a cousin
 * relationship is rejected at runtime.
 *
 * Domain code MUST NOT instantiate or use this. The constructor is
 * intentionally empty.
 *
 * @extends AggregateRoot<Identifier, DomainEvent>
 *
 * @psalm-suppress PropertyNotSetInConstructor — readonly id field is
 *                 deliberately unset; this instance never reads it.
 */
final class AggregateRootAccessor extends AggregateRoot
{
    /**
     * @psalm-suppress UnsafeInstantiation
     */
    public function __construct()
    {
        // Intentionally empty — the parent's required Identifier param is
        // bypassed because this class is never instantiated as a real
        // aggregate; it exists only to inherit protected-access rights.
    }

    #[Override]
    public function id(): Identifier
    {
        throw new LogicException(self::class . ' is a friend-class accessor; it carries no identity.');
    }

    /**
     * @template TId of Identifier
     * @template TEvent of DomainEvent
     *
     * @param AggregateRoot<TId, TEvent> $aggregate
     *
     * @return list<TEvent>
     */
    public function popRecordedEventsFrom(AggregateRoot $aggregate): array
    {
        /** @var list<TEvent> */
        return $aggregate->pullRecordedEvents();
    }

    /**
     * @template TId of Identifier
     * @template TEvent of DomainEvent
     *
     * @param AggregateRoot<TId, TEvent> $aggregate
     */
    public function extractVersion(AggregateRoot $aggregate): int
    {
        return $aggregate->version;
    }

    /**
     * @template TId of Identifier
     * @template TEvent of DomainEvent
     *
     * @param AggregateRoot<TId, TEvent> $aggregate
     */
    public function rehydrateVersionOn(AggregateRoot $aggregate, int $revision): void
    {
        $aggregate->rehydrateVersion($revision);
    }
}
