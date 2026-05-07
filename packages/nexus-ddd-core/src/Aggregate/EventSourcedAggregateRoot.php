<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;

/**
 * @psalm-api
 *
 * Base for event-sourced aggregates. State is reconstructed by replaying
 * the event stream via `replay()`. Subclasses define `applyXxx()` methods
 * that MUST be pure (no I/O, no recordThat(), no clock, no logging) — they
 * are also called during normal command handling to keep state in sync
 * with the freshly-recorded event.
 *
 * `applyXxx` resolution is convention-based (method name = `apply` + event
 * class short name), cached as class-scoped Closures by `ApplyDispatcher`.
 * Cross-namespace short-name collisions raise
 * `ApplyMethodAmbiguousException` at first dispatch.
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot implements EventSourceable
{
    private static ?ApplyDispatcher $dispatcher = null;

    /**
     * Record + apply: dispatch through applyXxx so state moves in lock-step
     * with the recorded stream, then append the event and bump version.
     */
    #[\Override]
    final protected function recordThat(DomainEvent $event): void
    {
        self::dispatcher()->dispatch($this, $event);
        parent::recordThat($event);
    }

    /** @param iterable<int, DomainEvent> $events */
    #[\Override]
    final public function replay(iterable $events): void
    {
        $dispatcher = self::dispatcher();

        foreach ($events as $event) {
            $dispatcher->dispatch($this, $event);
            $this->version++;
        }
    }

    private static function dispatcher(): ApplyDispatcher
    {
        return self::$dispatcher ??= new ApplyDispatcher();
    }
}
