<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;

/**
 * Class-based OOP alternative to the functional EventSourcedBehavior API.
 *
 * Users extend this class and override abstract methods to define their
 * domain logic. The actor delegates to PersistenceEngine::create() for
 * recovery and persistence handling.
 *
 * Immutable via clone — with* methods return new instances.
 *
 * @template S of object  The state type
 * @template E of object  The event type
 */
abstract class AbstractEventSourcedActor
{
    private ?SnapshotStore $snapshotStore = null;
    private SnapshotStrategy $snapshotStrategy;
    private RetentionPolicy $retentionPolicy;

    public function __construct(
        private readonly EventStore $eventStore,
    ) {
        $this->snapshotStrategy = SnapshotStrategy::never();
        $this->retentionPolicy = RetentionPolicy::none();
    }

    /**
     * The unique persistence identity for this actor.
     */
    abstract public function persistenceId(): PersistenceId;

    /**
     * The initial empty state before any events have been applied.
     *
     * @return S
     */
    abstract public function emptyState(): object;

    /**
     * Handle a command and return an Effect describing what should happen.
     *
     * @param S $state
     * @param ActorContext $ctx
     * @param object $command
     * @return Effect
     */
    abstract public function handleCommand(object $state, ActorContext $ctx, object $command): Effect;

    /**
     * Apply an event to the state, returning the new state.
     *
     * @param S $state
     * @param E $event
     * @return S
     */
    abstract public function applyEvent(object $state, object $event): object;

    /**
     * @return static
     */
    public function withSnapshotStore(SnapshotStore $store): static
    {
        $clone = clone $this;
        $clone->snapshotStore = $store;

        return $clone;
    }

    /**
     * @return static
     */
    public function withSnapshotStrategy(SnapshotStrategy $strategy): static
    {
        $clone = clone $this;
        $clone->snapshotStrategy = $strategy;

        return $clone;
    }

    /**
     * @return static
     */
    public function withRetention(RetentionPolicy $policy): static
    {
        $clone = clone $this;
        $clone->retentionPolicy = $policy;

        return $clone;
    }

    /**
     * Build a Behavior by delegating to PersistenceEngine::create().
     */
    public function toBehavior(): Behavior
    {
        return PersistenceEngine::create(
            $this->persistenceId(),
            $this->emptyState(),
            $this->handleCommand(...),
            $this->applyEvent(...),
            $this->eventStore,
            $this->snapshotStore,
            $this->snapshotStrategy,
            $this->retentionPolicy,
        );
    }

    /**
     * Build Props from the behavior, ready for spawning.
     */
    public function toProps(): Props
    {
        return Props::fromBehavior($this->toBehavior());
    }
}
