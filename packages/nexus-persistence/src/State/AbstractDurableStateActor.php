<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * Class-based OOP alternative to the functional DurableStateBehavior API.
 *
 * Users extend this class and override abstract methods to define their
 * domain logic. The actor delegates to DurableStateEngine::create() for
 * recovery and persistence handling.
 *
 * @template S of object  The state type
 *
 * @psalm-api
 */
abstract class AbstractDurableStateActor
{
    private string $writerId = '';

    /**
     * The unique persistence identity for this actor.
     */
    abstract public function persistenceId(): PersistenceId;

    /**
     * The initial empty state before any state has been persisted.
     *
     * @return S
     */
    abstract public function emptyState(): object;

    /**
     * Handle a command and return a DurableEffect describing what should happen.
     *
     * @param S $state
     */
    abstract public function handleCommand(object $state, ActorContext $ctx, object $command): DurableEffect;

    public function __construct(private readonly DurableStateStore $stateStore) {}

    /**
     * @return static
     */
    public function withWriterId(string $writerId): static
    {
        return clone($this, ['writerId' => $writerId]);
    }

    /**
     * Build a Behavior by delegating to DurableStateEngine::create().
     */
    public function toBehavior(): Behavior
    {
        return DurableStateEngine::create(
            $this->persistenceId(),
            $this->emptyState(),
            $this->handleCommand(...),
            $this->stateStore,
            $this->writerId,
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
