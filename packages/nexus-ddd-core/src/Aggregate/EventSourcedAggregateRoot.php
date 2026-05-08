<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Exception\ApplyDuringReplayException;
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
 * the event stream via `replay()`. Subclasses implement `apply()` —
 * typically a `match` over the concrete `TEvent` family — that mutates
 * `$this` in response to a domain event.
 *
 * `apply()` runs both during command execution (after `recordThat()` so
 * state moves in lock-step with the recorded stream) AND during replay
 * (without recording). It MUST be pure with respect to dispatch: do not
 * call `recordThat()` from inside `apply()`, do not call buses, do not
 * read clocks. The framework throws `ApplyDuringReplayException` if you
 * try to recordThat() during replay.
 *
 * Versioning: handle each version as a separate `match` arm.
 *
 *     protected function apply(DomainEvent $event): void
 *     {
 *         match (true) {
 *             $event instanceof V1\OrderPlaced => $this->whenV1OrderPlaced($event),
 *             $event instanceof V2\OrderPlaced => $this->whenV2OrderPlaced($event),
 *             $event instanceof OrderShipped  => $this->whenOrderShipped($event),
 *         };
 *     }
 *
 * PHP 8.0+ `match` without a `default` arm throws `\UnhandledMatchError`
 * on unknown events — fail-fast. Psalm's `MatchNotExhaustive` warns at
 * static-analysis time when the closed `TEvent` union is missing an arm.
 *
 * Framework hooks (replay, pullRecordedEvents, version, rehydrateVersion)
 * are protected; repositories reach them via `EventSourcedAggregateRootAccessor`.
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot implements EventSourceable
{
    private bool $isReplaying = false;

    /**
     * Mutate state in response to a domain event. Runs both during command
     * execution and during replay. Implementations are typically a
     * `match (true) { $event instanceof X => $this->whenX($event), ... }`.
     *
     * @param TEvent $event
     */
    abstract protected function apply(DomainEvent $event): void;

    /**
     * Record + apply: dispatch through `apply()` so state moves in lock-step
     * with the recorded stream, then append the event and bump version.
     *
     * **Ordering matters.** apply() runs *before* parent::recordThat. If
     * `apply()` throws, the event is NOT appended and version is NOT
     * bumped — the aggregate is left in its prior state.
     *
     * Throws `ApplyDuringReplayException` if called during replay (an
     * apply() method invoked recordThat() — bug).
     *
     * @param TEvent $event
     */
    #[Override]
    final protected function recordThat(DomainEvent $event): void
    {
        if ($this->isReplaying) {
            throw ApplyDuringReplayException::inApplyMethod();
        }

        $this->apply($event);
        parent::recordThat($event);
    }

    /**
     * Replay history without recording. Sets the `isReplaying` flag so any
     * accidental `recordThat()` from inside `apply()` throws.
     *
     * @param iterable<int, TEvent> $events
     */
    final protected function replay(iterable $events): void
    {
        $this->isReplaying = true;

        try {
            foreach ($events as $event) {
                $this->apply($event);
                $this->version++;
            }
        } finally {
            $this->isReplaying = false;
        }
    }
}
