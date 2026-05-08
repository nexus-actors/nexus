<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

/**
 * @psalm-api
 *
 * @template TId of Identifier
 * @template TEvent of DomainEvent
 * @extends AggregateRoot<TId, TEvent>
 * @implements EventSourceable<TEvent>
 *
 * Base for event-sourced aggregates. State is reconstructed by replaying
 * the event stream via `replay()`. Subclasses define `applyXxx()` methods
 * that MUST be pure (no I/O, no recordThat(), no clock, no logging) — they
 * are also called during normal command handling to keep state in sync
 * with the freshly-recorded event.
 *
 * `applyXxx` resolution is convention-based (method name = `apply` + event
 * class short name), with `#[AppliesTo(...)]` as an explicit override for
 * versioned events. Resolution + invocation are handled by `ApplyDispatcher`
 * (cached as class-scoped Closures after first dispatch).
 *
 * The dispatcher is constructor-injected. Repositories and aggregate
 * factories own a single dispatcher instance and pass it to every
 * aggregate they construct so the Closure cache is shared across
 * aggregates of the same class. Tests instantiate a fresh dispatcher
 * per case.
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot implements EventSourceable
{
    /** @param iterable<int, TEvent> $events */
    #[Override]
    final public function replay(iterable $events): void
    {
        foreach ($events as $event) {
            $this->dispatcher->dispatch($this, $event);
            $this->version++;
        }
    }

    /**
     * Record + apply: dispatch through applyXxx so state moves in lock-step
     * with the recorded stream, then append the event and bump version.
     *
     * **Ordering matters.** Dispatch runs *before* parent::recordThat. If
     * `applyXxx` throws, the event is NOT appended and version is NOT
     * bumped — the aggregate is left in its prior state, and
     * `pullRecordedEvents()` will not surface the failed event. This is
     * the "event-not-applied means event-not-recorded" semantic from
     * akka-typed; do not reorder these two lines without revisiting it.
     *
     * @param TEvent $event
     */
    #[Override]
    final protected function recordThat(DomainEvent $event): void
    {
        $this->dispatcher->dispatch($this, $event);

        parent::recordThat($event);
    }

    /** @param TId $id */
    protected function __construct(Identifier $id, private readonly ApplyDispatcher $dispatcher,) {
        parent::__construct($id);
    }
}
