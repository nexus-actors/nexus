<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Recovery\ReplayFilter;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Symfony\Component\Uid\Ulid;

/**
 * Fluent builder for event-sourced actor behaviors.
 *
 * Event sourcing stores a sequence of domain events and rebuilds current state
 * by replaying them. `EventSourcedBehavior` wires together a command handler
 * (which produces `Effect`s), an event handler (which folds events onto state),
 * an `EventStore`, and optional snapshot/retention policies — then compiles
 * everything into a `Behavior` via `toBehavior()`.
 *
 * On actor startup the `PersistenceEngine` loads the latest snapshot (if any),
 * replays subsequent events, and only then delivers the first user command.
 *
 * Example:
 * ```php
 * $behavior = EventSourcedBehavior::create(
 *     PersistenceId::of('Order', $orderId),
 *     new OrderState(),
 *     static fn (OrderState $state, ActorContext $ctx, object $cmd): Effect => match (true) {
 *         $cmd instanceof PlaceOrder => Effect::persist(new OrderPlaced($cmd->items)),
 *         $cmd instanceof CancelOrder => Effect::persist(new OrderCancelled()),
 *         default => Effect::none(),
 *     },
 *     static fn (OrderState $state, object $event): OrderState => match (true) {
 *         $event instanceof OrderPlaced    => $state->withItems($event->items),
 *         $event instanceof OrderCancelled => $state->cancel(),
 *         default => $state,
 *     },
 * )
 *     ->withEventStore($eventStore)
 *     ->withSnapshotStore($snapshotStore)
 *     ->withSnapshotStrategy(SnapshotStrategy::everyN(10))
 *     ->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: true))
 *     ->toBehavior();
 * ```
 *
 * @see Effect for the command-handler return type
 * @see PersistenceId for actor identity
 * @see EventStore for the event-stream storage contract
 * @see SnapshotStrategy for snapshot frequency configuration
 * @see RetentionPolicy for event pruning configuration
 *
 * @template S of object  The state type
 * @template E of object  The event type
 *
 * @psalm-api
 */
final readonly class EventSourcedBehavior
{
    /**
     * @param S $emptyState
     * @param Closure(S, ActorContext, object): Effect $commandHandler
     * @param Closure(S, E): S $eventHandler
     */
    private function __construct(
        private PersistenceId $persistenceId,
        private object $emptyState,
        private Closure $commandHandler,
        private Closure $eventHandler,
        private ?EventStore $eventStore = null,
        private ?SnapshotStore $snapshotStore = null,
        private ?SnapshotStrategy $snapshotStrategy = null,
        private ?RetentionPolicy $retentionPolicy = null,
        private Ulid $writerId = new Ulid(),
        private ?ReplayFilter $replayFilter = null,
    ) {}

    /**
     * Create a new EventSourcedBehavior builder.
     *
     * @template TState of object
     * @template TEvent of object
     *
     * @param PersistenceId $persistenceId Unique identity for this persistent entity
     * @param TState $emptyState Initial empty state before any events
     * @param Closure(TState, ActorContext, object): Effect $commandHandler Processes commands, returns Effect
     * @param Closure(TState, TEvent): TState $eventHandler Applies events to state (pure function)
     * @return self<TState, TEvent>
     */
    public static function create(
        PersistenceId $persistenceId,
        object $emptyState,
        Closure $commandHandler,
        Closure $eventHandler,
    ): self {
        /** @var self<TState, TEvent> TEvent only appears contravariantly (event-handler parameter), so Psalm cannot lift it out of the closure */
        return new self($persistenceId, $emptyState, $commandHandler, $eventHandler);
    }

    /** Set the event store used to persist and replay domain events. */
    public function withEventStore(EventStore $store): self
    {
        return clone($this, ['eventStore' => $store]);
    }

    /** Set an optional snapshot store for periodic state snapshots. */
    public function withSnapshotStore(SnapshotStore $store): self
    {
        return clone($this, ['snapshotStore' => $store]);
    }

    /** Set the strategy that determines when snapshots are taken (e.g. every N events). */
    public function withSnapshotStrategy(SnapshotStrategy $strategy): self
    {
        return clone($this, ['snapshotStrategy' => $strategy]);
    }

    /** Set the retention policy controlling how many snapshots and events are kept. */
    public function withRetention(RetentionPolicy $policy): self
    {
        return clone($this, ['retentionPolicy' => $policy]);
    }

    /**
     * Override the writer ID used to stamp persisted envelopes.
     *
     * Defaults to a freshly generated ULID. Override when migrating data or
     * running tests that need a deterministic writer identity.
     */
    public function withWriterId(Ulid $writerId): self
    {
        return clone($this, ['writerId' => $writerId]);
    }

    /**
     * Set the replay filter applied when loading events on startup.
     *
     * Defaults to `ReplayFilter::off()` (no conflict detection). Use
     * `ReplayFilter::fail()` to throw on writer conflicts, or one of the
     * repair modes to handle multi-writer scenarios gracefully.
     */
    public function withReplayFilter(ReplayFilter $filter): self
    {
        return clone($this, ['replayFilter' => $filter]);
    }

    /**
     * Build the final Behavior using PersistenceEngine.
     *
     * @throws LogicException if EventStore has not been set
     */
    public function toBehavior(): Behavior
    {
        return PersistenceEngine::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->eventHandler,
            $this->eventStore ?? throw new LogicException(
                'EventStore is required — call withEventStore() before toBehavior()',
            ),
            $this->snapshotStore,
            $this->snapshotStrategy,
            $this->retentionPolicy,
            $this->writerId,
            $this->replayFilter,
        );
    }
}
