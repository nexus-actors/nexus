<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;

/**
 * Immutable behavior definition — the heart of every actor.
 *
 * A Behavior describes what an actor does when it receives a message. You never
 * instantiate Behavior subclasses directly; instead use the static factory methods
 * (receive, withState, setup, withTimers, withStash) which return the appropriate
 * concrete subtype. Each message handler returns a Behavior to indicate what
 * the actor should do next — keep the same behavior (same()), stop (stopped()),
 * or switch to a new behavior.
 *
 * Example — stateless closure actor:
 * ```php
 * $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
 *     if ($msg instanceof Greet) {
 *         $ctx->log()->info('Hello, ' . $msg->name);
 *     }
 *     return Behavior::same();
 * });
 * $ref = $system->spawn(Props::fromBehavior($behavior), 'greeter');
 * ```
 *
 * Example — stateful closure actor:
 * ```php
 * $behavior = Behavior::withState(0, static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
 *     return match (true) {
 *         $msg instanceof Increment => BehaviorWithState::next($count + 1),
 *         $msg instanceof GetCount  => BehaviorWithState::same(),
 *         default                   => BehaviorWithState::same(),
 *     };
 * });
 * ```
 *
 * @see Props for wiring a Behavior into a spawnable actor
 * @see BehaviorWithState for the stateful handler return type
 * @see ActorContext for the context available inside handlers
 *
 * @psalm-api
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
     * Create a stateless behavior from a message-handler closure.
     *
     * The closure receives the current ActorContext and the message, and must
     * return a Behavior indicating what the actor should do next. Return
     * Behavior::same() to keep the current behavior unchanged.
     *
     * @template U of object
     * @param Closure(ActorContext<U>, U): Behavior<U> $handler
     * @return ReceiveBehavior<U>
     */
    public static function receive(Closure $handler): ReceiveBehavior
    {
        return new ReceiveBehavior($handler);
    }

    /**
     * Create a stateful behavior that carries typed state alongside the message handler.
     *
     * The handler receives the context, the message, and the current state, and must
     * return a BehaviorWithState indicating the next state. The state is private to
     * the actor and never shared outside it.
     *
     * @template U of object
     * @template S
     * @param S $initialState The initial value of the actor's state.
     * @param Closure(ActorContext<U>, U, S): BehaviorWithState<U, S> $handler
     * @return WithStateBehavior<U, S>
     */
    public static function withState(mixed $initialState, Closure $handler): WithStateBehavior
    {
        return new WithStateBehavior($handler, $initialState);
    }

    /**
     * Create a lazily-initialized behavior from a setup factory closure.
     *
     * The factory runs once when the actor starts (before any user messages),
     * receiving the ActorContext so it can spawn children, subscribe to events,
     * or acquire resources before returning the actual message-handling Behavior.
     *
     * @template U of object
     * @param Closure(ActorContext<U>): Behavior<U> $factory Called once at actor startup.
     * @return SetupBehavior<U>
     */
    public static function setup(Closure $factory): SetupBehavior
    {
        return new SetupBehavior($factory);
    }

    /**
     * Create a behavior with access to a TimerScheduler for delayed and periodic messages.
     *
     * The factory receives a TimerScheduler that allows the actor to schedule
     * messages to itself without needing access to the ActorContext directly.
     * Timers are automatically cancelled when the actor stops.
     *
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
     * Create a behavior with a bounded stash buffer for deferring messages.
     *
     * The factory receives a StashBuffer that allows the actor to stash incoming
     * messages and unstash them later (e.g. after completing initialization or a
     * persistence recovery phase). Messages beyond capacity are dropped.
     *
     * @template U of object
     * @param int $capacity Maximum number of messages that can be stashed.
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
     * Wrap an existing behavior with a custom supervision strategy.
     *
     * The supervision strategy overrides the default one-for-one restart policy
     * for this actor's children. Normally you configure supervision via Props;
     * use this factory when you need different strategies per behavior variant.
     *
     * @template U of object
     * @param Behavior<U> $inner The behavior to wrap.
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
     * Signal that the actor should keep its current behavior unchanged.
     *
     * The most common return value from a message handler — return this when
     * you have processed the message but do not need to change the behavior.
     *
     * @return SameBehavior<T>
     */
    public static function same(): SameBehavior
    {
        /** @var SameBehavior<T> */
        return new SameBehavior();
    }

    /**
     * Signal that the actor should stop after processing the current message.
     *
     * The actor delivers PostStop, stops its children, and closes its mailbox.
     * Any messages still in the mailbox are forwarded to dead letters.
     *
     * @return StoppedBehavior<T>
     */
    public static function stopped(): StoppedBehavior
    {
        /** @var StoppedBehavior<T> */
        return new StoppedBehavior();
    }

    /**
     * Signal that the current message was not handled by this behavior.
     *
     * Unhandled messages are forwarded to the dead-letter channel so they
     * can be monitored without causing the actor to crash.
     *
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
