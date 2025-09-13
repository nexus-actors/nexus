<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Lifecycle\Signal;

/**
 * @psalm-api
 *
 * Immutable behavior definition for actors.
 *
 * Template parameter T represents the message protocol the actor handles.
 *
 * @template T of object
 */
final readonly class Behavior
{
    /**
     * @param Option<\Closure> $handler
     * @param Option<\Closure> $signalHandler
     * @param Option<mixed> $initialState
     */
    private function __construct(
        private BehaviorTag $tag,
        private Option $handler,
        private Option $signalHandler,
        private Option $initialState,
    ) {}

    /**
     * @template U of object
     * @param \Closure(ActorContext<U>, U): Behavior<U> $handler
     * @return Behavior<U>
     */
    public static function receive(Closure $handler): self
    {
        /** @var Behavior<U> */
        return new self(BehaviorTag::Receive, Option::some($handler), self::noSignalHandler(), self::noState());
    }

    /**
     * @template U of object
     * @template S
     * @param S $initialState
     * @param \Closure(ActorContext<U>, U, S): BehaviorWithState<U, S> $handler
     * @return Behavior<U>
     */
    public static function withState(mixed $initialState, Closure $handler): self
    {
        /** @var Behavior<U> */
        return new self(
            BehaviorTag::WithState,
            Option::some($handler),
            self::noSignalHandler(),
            Option::some($initialState),
        );
    }

    /**
     * @template U of object
     * @param \Closure(ActorContext<U>): Behavior<U> $factory
     * @return Behavior<U>
     */
    public static function setup(Closure $factory): self
    {
        /** @var Behavior<U> */
        return new self(BehaviorTag::Setup, Option::some($factory), self::noSignalHandler(), self::noState());
    }

    /**
     * @return Behavior<T>
     */
    public static function same(): self
    {
        /** @var Behavior<T> */
        return new self(BehaviorTag::Same, self::noHandler(), self::noSignalHandler(), self::noState());
    }

    /**
     * @return Behavior<T>
     */
    public static function stopped(): self
    {
        /** @var Behavior<T> */
        return new self(BehaviorTag::Stopped, self::noHandler(), self::noSignalHandler(), self::noState());
    }

    /**
     * @return Behavior<T>
     */
    public static function unhandled(): self
    {
        /** @var Behavior<T> */
        return new self(BehaviorTag::Unhandled, self::noHandler(), self::noSignalHandler(), self::noState());
    }

    /**
     * @return Behavior<T>
     */
    public static function empty(): self
    {
        /** @var Behavior<T> */
        return new self(BehaviorTag::Empty, self::noHandler(), self::noSignalHandler(), self::noState());
    }

    /**
     * @param \Closure(ActorContext<T>, Signal): Behavior<T> $handler
     * @return Behavior<T>
     * @psalm-suppress UnusedParam $handler is used in Option::some($handler)
     */
    public function onSignal(Closure $handler): self
    {
        /** @var Behavior<T> */
        return new self($this->tag, $this->handler, Option::some($handler), $this->initialState);
    }

    public function tag(): BehaviorTag
    {
        return $this->tag;
    }

    public function isSame(): bool
    {
        return $this->tag === BehaviorTag::Same;
    }

    public function isStopped(): bool
    {
        return $this->tag === BehaviorTag::Stopped;
    }

    public function isUnhandled(): bool
    {
        return $this->tag === BehaviorTag::Unhandled;
    }

    /**
     * @return Option<\Closure>
     */
    public function handler(): Option
    {
        return $this->handler;
    }

    /**
     * @return Option<\Closure>
     */
    public function signalHandler(): Option
    {
        return $this->signalHandler;
    }

    /**
     * @return Option<mixed>
     */
    public function initialState(): Option
    {
        return $this->initialState;
    }

    /**
     * @return Option<\Closure>
     */
    private static function noHandler(): Option
    {
        /** @var Option<\Closure> fp4php returns Option<empty>, covariant to Option<\Closure> */
        return Option::none();
    }

    /**
     * @return Option<\Closure>
     */
    private static function noSignalHandler(): Option
    {
        /** @var Option<\Closure> fp4php returns Option<empty>, covariant to Option<\Closure> */
        return Option::none();
    }

    /**
     * @return Option<mixed>
     */
    private static function noState(): Option
    {
        /** @var Option<mixed> */
        return Option::none();
    }
}
