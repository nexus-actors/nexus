<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;

/**
 * @psalm-api
 *
 * @template TEvent of DomainEvent
 * @extends AggregateRoot<TEvent>
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
 * **Concurrency.** The dispatcher is held in a single static slot on this
 * class. Under a single-process Fiber runtime that is safe; under multi-
 * worker runtimes (e.g. Swoole's worker pool) the dispatcher is shared
 * across coroutines within a worker but isolated across workers (each
 * worker has its own PHP heap). Frameworks that want a per-context
 * dispatcher (per worker, per test, per coroutine pool) replace the
 * default via `setDispatcher()`. Tests reset between cases via the same
 * hook.
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot implements EventSourceable
{
    private static ?ApplyDispatcher $dispatcher = null;

    /**
     * Replace the apply dispatcher. Returns the previous instance so the
     * caller can restore it (typically used by tests for isolation, or by
     * framework wiring code that scopes a dispatcher to a worker / test /
     * coroutine context).
     */
    public static function setDispatcher(?ApplyDispatcher $dispatcher): ?ApplyDispatcher
    {
        $previous = self::$dispatcher;
        self::$dispatcher = $dispatcher;

        return $previous;
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
    #[\Override]
    final protected function recordThat(DomainEvent $event): void
    {
        self::dispatcher()->dispatch($this, $event);
        parent::recordThat($event);
    }

    /** @param iterable<int, TEvent> $events */
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
