<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Recovery\ReplayFilter;
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
 *
 * @psalm-api
 */
abstract class AbstractEventSourcedActor
{
    private ?SnapshotStore $snapshotStore = null;
    private SnapshotStrategy $snapshotStrategy;
    private RetentionPolicy $retentionPolicy;
    private string $writerId = '';
    private ?ReplayFilter $replayFilter = null;

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

    public function __construct(private readonly EventStore $eventStore)
    {
        $this->snapshotStrategy = SnapshotStrategy::never();
        $this->retentionPolicy = RetentionPolicy::none();
    }

    /**
     * @return static
     */
    public function withSnapshotStore(SnapshotStore $store): static
    {
        return clone($this, ['snapshotStore' => $store]);
    }

    /**
     * @return static
     */
    public function withSnapshotStrategy(SnapshotStrategy $strategy): static
    {
        return clone($this, ['snapshotStrategy' => $strategy]);
    }

    /**
     * @return static
     */
    public function withRetention(RetentionPolicy $policy): static
    {
        return clone($this, ['retentionPolicy' => $policy]);
    }

    /**
     * @return static
     */
    public function withWriterId(string $writerId): static
    {
        return clone($this, ['writerId' => $writerId]);
    }

    /**
     * @return static
     */
    public function withReplayFilter(ReplayFilter $filter): static
    {
        return clone($this, ['replayFilter' => $filter]);
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
            $this->writerId,
            $this->replayFilter,
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
