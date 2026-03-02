<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Result of a stateful behavior handler.
 *
 * @template T of object
 * @template S
 */
final readonly class BehaviorWithState
{
    /**
     * @param ?Behavior<T> $behavior  null = keep current behavior
     * @param S $state
     */
    private function __construct(
        private ?Behavior $behavior,
        private mixed $state,
        private bool $hasState,
        private bool $stopped,
    ) {}

    /**
     * Same behavior, new state.
     *
     * @template NS
     * @param NS $state
     * @return BehaviorWithState<object, NS>
     */
    public static function next(mixed $state): self
    {
        /** @var BehaviorWithState<object, NS> */
        return new self(null, $state, true, false);
    }

    /**
     * Keep both behavior and state.
     *
     * @return BehaviorWithState<T, S>
     */
    public static function same(): self
    {
        /** @var BehaviorWithState<T, S> */
        return new self(null, null, false, false);
    }

    /**
     * Stop the actor.
     *
     * @return BehaviorWithState<T, S>
     */
    public static function stopped(): self
    {
        /** @var BehaviorWithState<T, S> */
        return new self(null, null, false, true);
    }

    /**
     * Switch both behavior and state.
     *
     * @template U of object
     * @template NS
     * @param Behavior<U> $behavior
     * @param NS $state
     * @return BehaviorWithState<U, NS>
     */
    public static function withBehavior(Behavior $behavior, mixed $state): self
    {
        /** @var BehaviorWithState<U, NS> */
        return new self($behavior, $state, true, false);
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * The new behavior to switch to, or null to keep the current behavior.
     *
     * @return ?Behavior<T>
     */
    public function behavior(): ?Behavior
    {
        return $this->behavior;
    }

    /**
     * The new state value. Only meaningful when hasNewState() returns true.
     *
     * @return S
     */
    public function state(): mixed
    {
        return $this->state;
    }

    /**
     * Whether the handler returned a new state (even if that state is null).
     */
    public function hasNewState(): bool
    {
        return $this->hasState;
    }
}
