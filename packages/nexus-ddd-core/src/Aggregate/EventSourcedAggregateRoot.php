<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;

/**
 * @psalm-api
 *
 * Base for event-sourced aggregates. State is reconstructed by replaying
 * events via the `replay()` method. Subclasses define applyXxx() methods that
 * MUST be pure (no I/O, no recordThat(), no clock, no logging).
 *
 * Implements `EventSourceable` — only ES aggregates are event-sourceable;
 * StatefulAggregateRoot does NOT implement this interface (it persists state,
 * doesn't replay).
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot implements EventSourceable
{
    /** @param iterable<int, object> $events */
    #[\Override]
    final public function replay(iterable $events): void
    {
        $dispatcher = self::dispatcher();

        foreach ($events as $event) {
            $dispatcher->dispatch($this, $event);
            $this->version++;
        }
    }
}
