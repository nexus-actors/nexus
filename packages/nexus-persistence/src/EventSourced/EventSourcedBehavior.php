<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;

/**
 * Immutable builder for creating event-sourced Behaviors.
 *
 * Provides a fluent API to configure persistence options before
 * converting to a Behavior via toBehavior().
 *
 * @template S of object  The state type
 * @template E of object  The event type
 *
 * @psalm-api
 */
final class EventSourcedBehavior
{
    /** @psalm-suppress UnusedConstructor Called by create() */
    private function __construct(
        private readonly PersistenceId $persistenceId,
        private readonly object $emptyState,
        private readonly Closure $commandHandler,
        private readonly Closure $eventHandler,
        private readonly ?EventStore $eventStore = null,
        private readonly ?SnapshotStore $snapshotStore = null,
        private readonly ?SnapshotStrategy $snapshotStrategy = null,
        private readonly ?RetentionPolicy $retentionPolicy = null,
    ) {}

    /**
     * Create a new EventSourcedBehavior builder.
     *
     * @param PersistenceId $persistenceId Unique identity for this persistent entity
     * @param object $emptyState Initial empty state before any events
     * @param Closure $commandHandler Processes commands, returns Effect
     * @param Closure $eventHandler Applies events to state (pure function)
     *
     * @psalm-suppress UnusedParam Parameters are stored via constructor for later use
     */
    public static function create(
        PersistenceId $persistenceId,
        object $emptyState,
        Closure $commandHandler,
        Closure $eventHandler,
    ): self {
        return new self($persistenceId, $emptyState, $commandHandler, $eventHandler);
    }

    public function withEventStore(EventStore $store): self
    {
        return clone($this, ['eventStore' => $store]);
    }

    public function withSnapshotStore(SnapshotStore $store): self
    {
        return clone($this, ['snapshotStore' => $store]);
    }

    public function withSnapshotStrategy(SnapshotStrategy $strategy): self
    {
        return clone($this, ['snapshotStrategy' => $strategy]);
    }

    public function withRetention(RetentionPolicy $policy): self
    {
        return clone($this, ['retentionPolicy' => $policy]);
    }

    /**
     * Build the final Behavior using PersistenceEngine.
     *
     * @throws LogicException if EventStore has not been set
     *
     * @psalm-suppress MixedArgumentTypeCoercion Stored closures lose generic type info
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
        );
    }
}
