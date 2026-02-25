<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * Immutable builder for creating durable-state Behaviors.
 *
 * Provides a fluent API to configure persistence options before
 * converting to a Behavior via toBehavior().
 *
 * @template S of object  The state type
 *
 * @psalm-api
 */
final class DurableStateBehavior
{
    /** @psalm-suppress UnusedConstructor Called by create() */
    private function __construct(
        private readonly PersistenceId $persistenceId,
        private readonly object $emptyState,
        private readonly Closure $commandHandler,
        private readonly ?DurableStateStore $stateStore = null,
        private readonly string $writerId = '',
    ) {}

    /**
     * Create a new DurableStateBehavior builder.
     *
     * @param PersistenceId $persistenceId Unique identity for this persistent entity
     * @param object $emptyState Initial empty state before any persisted state
     * @param Closure $commandHandler Processes commands, returns DurableEffect
     *
     * @psalm-suppress UnusedParam Parameters are stored via constructor for later use
     */
    public static function create(PersistenceId $persistenceId, object $emptyState, Closure $commandHandler): self
    {
        return new self($persistenceId, $emptyState, $commandHandler);
    }

    public function withStateStore(DurableStateStore $store): self
    {
        return clone($this, ['stateStore' => $store]);
    }

    public function withWriterId(string $writerId): self
    {
        return clone($this, ['writerId' => $writerId]);
    }

    /**
     * Build the final Behavior using DurableStateEngine.
     *
     * @throws LogicException if DurableStateStore has not been set
     *
     * @psalm-suppress MixedArgumentTypeCoercion Stored closures lose generic type info
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
            $this->writerId,
        );
    }
}
