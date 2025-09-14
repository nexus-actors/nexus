<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

/**
 * @psalm-api
 *
 * Contract for stateful class-based actors.
 *
 * Like ActorHandler but with explicit state management. The actor provides
 * an initial state via initialState() and receives the current state on each
 * handle() call. Returns BehaviorWithState to update state or stop.
 *
 * @template T of object
 * @template S
 */
interface StatefulActorHandler
{
    /**
     * Provide the initial state for this actor.
     *
     * @return S
     */
    public function initialState(): mixed;

    /**
     * Handle an incoming message with the current state.
     *
     * @param ActorContext<T> $ctx
     * @param T $message
     * @param S $state
     * @return BehaviorWithState<T, S>
     */
    public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState;
}
