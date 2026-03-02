<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;

/**
 * @psalm-api
 *
 * Immutable behavior definition for actors. Each concrete subclass represents
 * one distinct behavior variant — use instanceof checks to distinguish them.
 *
 * Template parameter T represents the message protocol the actor handles.
 *
 * @template T of object
 */
abstract readonly class Behavior
{
    /**
     * Returns the signal handler attached via onSignal(), or null if none.
     *
     * @return Closure(ActorContext<T>, Signal): Behavior<T>|null
     */
    abstract public function signalHandler(): ?Closure;

    /**
     * Returns a new behavior of the same type with the given signal handler attached.
     *
     * @param Closure(ActorContext<T>, Signal): Behavior<T> $handler
     * @return static
     */
    abstract public function onSignal(Closure $handler): static;

    // ---- Factory methods ----

    /**
     * @template U of object
     * @param Closure(ActorContext<U>, U): Behavior<U> $handler
     * @return ReceiveBehavior<U>
     */
    public static function receive(Closure $handler): ReceiveBehavior
    {
        return new ReceiveBehavior($handler);
    }

    /**
     * @template U of object
     * @template S
     * @param S $initialState
     * @param Closure(ActorContext<U>, U, S): BehaviorWithState<U, S> $handler
     * @return WithStateBehavior<U, S>
     */
    public static function withState(mixed $initialState, Closure $handler): WithStateBehavior
    {
        return new WithStateBehavior($handler, $initialState);
    }

    /**
     * @template U of object
     * @param Closure(ActorContext<U>): Behavior<U> $factory
     * @return SetupBehavior<U>
     */
    public static function setup(Closure $factory): SetupBehavior
    {
        return new SetupBehavior($factory);
    }

    /**
     * @template U of object
     * @param Closure(TimerScheduler): Behavior<U> $factory
     * @return WithTimersBehavior<U>
     * @psalm-suppress UndefinedDocblockClass TimerScheduler will be created in a future task
     * @psalm-suppress UnusedParam $factory is stored for resolution by ActorCell
     */
    public static function withTimers(Closure $factory): WithTimersBehavior
    {
        return new WithTimersBehavior($factory);
    }

    /**
     * @template U of object
     * @param Closure(StashBuffer): Behavior<U> $factory
     * @return WithStashBehavior<U>
     * @psalm-suppress UndefinedDocblockClass StashBuffer will be created in a future task
     * @psalm-suppress UnusedParam $capacity and $factory are stored for resolution by ActorCell
     */
    public static function withStash(int $capacity, Closure $factory): WithStashBehavior
    {
        return new WithStashBehavior($factory, $capacity);
    }

    /**
     * @template U of object
     * @param Behavior<U> $inner
     * @return SupervisedBehavior<U>
     */
    public static function supervise(self $inner, SupervisionStrategy $strategy): SupervisedBehavior
    {
        return new SupervisedBehavior($inner, $strategy);
    }

    /**
     * @internal Used by StashBuffer to create a replay behavior.
     *
     * @template U of object
     * @param list<Envelope> $envelopes
     * @param Behavior<U> $target
     * @return UnstashAllBehavior<U>
     */
    public static function unstashAllReplay(array $envelopes, self $target): UnstashAllBehavior
    {
        return new UnstashAllBehavior($envelopes, $target);
    }

    /**
     * @return SameBehavior<T>
     */
    public static function same(): SameBehavior
    {
        /** @var SameBehavior<T> */
        return new SameBehavior();
    }

    /**
     * @return StoppedBehavior<T>
     */
    public static function stopped(): StoppedBehavior
    {
        /** @var StoppedBehavior<T> */
        return new StoppedBehavior();
    }

    /**
     * @return UnhandledBehavior<T>
     */
    public static function unhandled(): UnhandledBehavior
    {
        /** @var UnhandledBehavior<T> */
        return new UnhandledBehavior();
    }

    /**
     * @return EmptyBehavior<T>
     */
    public static function empty(): EmptyBehavior
    {
        /** @var EmptyBehavior<T> */
        return new EmptyBehavior();
    }
}
