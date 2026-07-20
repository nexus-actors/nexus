<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Persistence\PersistenceId;
use Symfony\Component\Uid\Ulid;

/**
 * Fluent builder for durable-state actor behaviors.
 *
 * Durable state is the simpler persistence model: the actor's full current
 * state is stored as a single snapshot on every `DurableEffect::persist()`
 * call, with no event history retained. This trades auditability for lower
 * storage overhead and faster recovery (no event replay — just load the latest
 * snapshot and you're ready).
 *
 * On actor startup the `DurableStateEngine` loads the latest persisted state
 * from the `DurableStateStore` and delivers it as the initial state before any
 * user command arrives.
 *
 * Example:
 * ```php
 * $behavior = DurableStateBehavior::create(
 *     PersistenceId::of('UserProfile', $userId),
 *     new UserProfile(),
 *     static fn (UserProfile $state, ActorContext $ctx, object $cmd): DurableEffect => match (true) {
 *         $cmd instanceof UpdateEmail  => DurableEffect::persist($state->withEmail($cmd->email)),
 *         $cmd instanceof GetProfile   => DurableEffect::reply($ctx->sender(), $state),
 *         default => DurableEffect::none(),
 *     },
 * )
 *     ->withStateStore($stateStore)
 *     ->toBehavior();
 * ```
 *
 * @see DurableEffect for the command-handler return type
 * @see PersistenceId for actor identity
 * @see EventSourcedBehavior for the event-sourced alternative with full history
 *
 * @template S of object  The state type
 *
 * @psalm-api
 */
final readonly class DurableStateBehavior
{
    /**
     * @param S $emptyState
     * @param Closure(S, ActorContext, object): DurableEffect $commandHandler
     */
    private function __construct(
        private PersistenceId $persistenceId,
        private object $emptyState,
        private Closure $commandHandler,
        private ?DurableStateStore $stateStore = null,
        private Ulid $writerId = new Ulid(),
    ) {}

    /**
     * Create a new DurableStateBehavior builder.
     *
     * @template TState of object
     *
     * @param PersistenceId $persistenceId Unique identity for this persistent entity
     * @param TState $emptyState Initial empty state before any persisted state
     * @param Closure(TState, ActorContext, object): DurableEffect $commandHandler Processes commands, returns DurableEffect
     * @return self<TState>
     */
    public static function create(PersistenceId $persistenceId, object $emptyState, Closure $commandHandler): self
    {
        return new self($persistenceId, $emptyState, $commandHandler);
    }

    /** Set the state store used to load and persist full state snapshots. */
    public function withStateStore(DurableStateStore $store): self
    {
        return clone($this, ['stateStore' => $store]);
    }

    /**
     * Override the writer ID stamped on persisted state envelopes.
     *
     * Defaults to a freshly generated ULID. Override for deterministic tests
     * or data-migration scenarios.
     */
    public function withWriterId(Ulid $writerId): self
    {
        return clone($this, ['writerId' => $writerId]);
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
            $this->writerId,
        );
    }
}
