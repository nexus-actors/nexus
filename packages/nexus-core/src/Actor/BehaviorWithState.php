<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Functional\Option\Option;

/**
 * Result of a stateful behavior handler.
 *
 * Allows the handler to indicate new state, same state, stopped, or
 * a complete behavior switch.
 *
 * @template T of object
 * @template S
 */
final readonly class BehaviorWithState
{
    /**
     * @param Option<Behavior<T>> $behavior
     * @param Option<S> $state
     */
    private function __construct(
        private Option $behavior,
        private Option $state,
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
        return new self(self::noBehavior(), Option::some($state), false);
    }

    /**
     * Keep both behavior and state.
     *
     * @return BehaviorWithState<T, S>
     */
    public static function same(): self
    {
        /** @var BehaviorWithState<T, S> */
        return new self(self::noBehavior(), self::noState(), false);
    }

    /**
     * Stop the actor.
     *
     * @return BehaviorWithState<T, S>
     */
    public static function stopped(): self
    {
        /** @var BehaviorWithState<T, S> */
        return new self(self::noBehavior(), self::noState(), true);
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
        return new self(Option::some($behavior), Option::some($state), false);
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * @return Option<Behavior<T>>
     */
    public function behavior(): Option
    {
        return $this->behavior;
    }

    /**
     * @return Option<S>
     */
    public function state(): Option
    {
        return $this->state;
    }

    /**
     * @return Option<Behavior<object>>
     */
    private static function noBehavior(): Option
    {
        /** @var Option<Behavior<object>> fp4php returns Option<empty>, covariant to Option<Behavior<object>> */
        $none = Option::none(); // @phpstan-ignore varTag.type

        return $none;
    }

    /**
     * @return Option<mixed>
     */
    private static function noState(): Option
    {
        /** @var Option<mixed> */
        $none = Option::none();

        return $none;
    }
}
