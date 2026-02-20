<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Persistence\Locking\LockingStrategy;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * Immutable builder for creating durable-state Behaviors.
 *
 * Provides a fluent API to configure persistence options before
 * converting to a Behavior via toBehavior().
 *
 * @template S of object  The state type
 */
final class DurableStateBehavior
{
    private function __construct(
        private readonly PersistenceId $persistenceId,
        private readonly object $emptyState,
        private readonly Closure $commandHandler,
        private readonly ?DurableStateStore $stateStore = null,
        private readonly ?LockingStrategy $lockingStrategy = null,
    ) {}

    /**
     * Create a new DurableStateBehavior builder.
     *
     * @param PersistenceId $persistenceId Unique identity for this persistent entity
     * @param S $emptyState Initial empty state before any persisted state
     * @param Closure(S, \Monadial\Nexus\Core\Actor\ActorContext, object): DurableEffect $commandHandler Processes commands, returns DurableEffect
     */
    public static function create(PersistenceId $persistenceId, object $emptyState, Closure $commandHandler): self
    {
        return new self($persistenceId, $emptyState, $commandHandler);
    }

    public function withStateStore(DurableStateStore $store): self
    {
        return clone($this, ['stateStore' => $store]);
    }

    public function withLockingStrategy(LockingStrategy $strategy): self
    {
        return clone($this, ['lockingStrategy' => $strategy]);
    }

    /**
     * Build the final Behavior using DurableStateEngine.
     *
     * @throws LogicException if DurableStateStore has not been set
     */
    public function toBehavior(): Behavior
    {
        return DurableStateEngine::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->stateStore ?? throw new LogicException(
                'DurableStateStore is required — call withStateStore() before toBehavior()',
            ),
            $this->lockingStrategy,
        );
    }
}
