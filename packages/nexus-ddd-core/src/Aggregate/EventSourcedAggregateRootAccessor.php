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
 * Friend-class accessor for repository-level access to event-sourced
 * aggregate framework hooks (replay, pullRecordedEvents, version,
 * rehydrateVersion). PHP has no native friend-class mechanism; this
 * class extends `EventSourcedAggregateRoot` to gain protected-access
 * rights across the whole hierarchy via PHP's "any instance of the
 * same class hierarchy can read/call protected members of any other
 * instance" rule.
 *
 * Single concrete class — works for every aggregate that extends
 * `EventSourcedAggregateRoot`. Stateless; share one instance across
 * all repositories in the application.
 *
 * Domain code MUST NOT instantiate or use this. The constructor is
 * intentionally empty — this class is never used as a real aggregate,
 * only as a typed accessor.
 *
 * @extends EventSourcedAggregateRoot<Identifier, DomainEvent>
 *
 * @psalm-suppress PropertyNotSetInConstructor — readonly id field is
 *                 deliberately unset; this instance never reads it.
 */
final class EventSourcedAggregateRootAccessor extends EventSourcedAggregateRoot
{
    /**
     * @psalm-suppress UnsafeInstantiation
     */
    public function __construct() {}

    #[Override]
    public function id(): Identifier
    {
        throw new LogicException(self::class . ' is a friend-class accessor; it carries no identity.');
    }

    #[Override]
    protected function apply(DomainEvent $event): void
    {
        throw new LogicException(self::class . ' is a friend-class accessor; it cannot apply events.');
    }

    /**
     * @template TId of Identifier
     * @template TEvent of DomainEvent
     * @param EventSourcedAggregateRoot<TId, TEvent> $aggregate
     * @param iterable<int, TEvent> $events
     */
    public function replayOn(EventSourcedAggregateRoot $aggregate, iterable $events): void
    {
        $aggregate->replay($events);
    }

    /**
     * @template TId of Identifier
     * @template TEvent of DomainEvent
     * @param AggregateRoot<TId, TEvent> $aggregate
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
     * @param AggregateRoot<TId, TEvent> $aggregate
     */
    public function extractVersion(AggregateRoot $aggregate): int
    {
        return $aggregate->version;
    }

    /**
     * @template TId of Identifier
     * @template TEvent of DomainEvent
     * @param AggregateRoot<TId, TEvent> $aggregate
     */
    public function rehydrateVersionOn(AggregateRoot $aggregate, int $revision): void
    {
        $aggregate->rehydrateVersion($revision);
    }
}
