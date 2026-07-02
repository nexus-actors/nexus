<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\NoSenderException;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Psr\Log\LoggerInterface;

/**
 * Runtime context injected into every actor handler invocation.
 *
 * `ActorContext<T>` is the primary API surface an actor uses during message
 * processing. It exposes the actor's own reference, its position in the
 * supervision tree, child management, death-watch subscriptions, message
 * stashing, timer scheduling, background task spawning, and PSR-3 logging.
 * A fresh context is passed on every handler call — never cache or store it.
 *
 * Example:
 * ```php
 * $behavior = Behavior::setup(static function (ActorContext $ctx): Behavior {
 *     $child = $ctx->spawn(Props::fromBehavior($childBehavior), 'worker');
 *     $ctx->watch($child);
 *
 *     return Behavior::receive(static function (ActorContext $ctx, object $msg) use ($child): Behavior {
 *         if ($msg instanceof DoWork) {
 *             $child->tell($msg);
 *         }
 *         return Behavior::same();
 *     })->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
 *         if ($signal instanceof Terminated) {
 *             $ctx->log()->warning('Worker terminated');
 *             return Behavior::stopped();
 *         }
 *         return Behavior::same();
 *     });
 * });
 * ```
 *
 * @see ActorRef for the type-safe reference returned by spawn()
 * @see Props for actor configuration passed to spawn()
 * @see Behavior for the return type of handler closures
 *
 * @psalm-api
 *
 * @template T of object
 */
interface ActorContext
{
    /** @return ActorRef<T> */
    public function self(): ActorRef;

    /** @return ?ActorRef<object> */
    public function parent(): ?ActorRef;

    /** Return the actor's canonical path in the supervision tree. */
    public function path(): ActorPath;

    /**
     * @template C of object
     * @param Props<C> $props
     * @return ActorRef<C>
     * @throws ActorInitializationException
     */
    public function spawn(Props $props, string $name): ActorRef;

    /**
     * Gracefully stop a direct child actor.
     *
     * The child finishes processing its current message, then receives a
     * `PostStop` signal before being removed from the supervision tree.
     *
     * @param ActorRef<object> $child
     */
    public function stop(ActorRef $child): void;

    /**
     * Return the child actor with the given name, or null if it does not exist.
     *
     * @return ?ActorRef<object>
     */
    public function child(string $name): ?ActorRef;

    /**
     * Return all live direct children keyed by name.
     *
     * @return array<string, ActorRef<object>>
     */
    public function children(): array;

    /**
     * Subscribe to termination notifications for `$target`.
     *
     * When `$target` stops, this actor receives a `Terminated` signal via its
     * `onSignal()` handler.
     *
     * @param ActorRef<object> $target
     */
    public function watch(ActorRef $target): void;

    /**
     * Cancel a previously registered death-watch subscription.
     *
     * @param ActorRef<object> $target
     */
    public function unwatch(ActorRef $target): void;

    /**
     * Deliver `$message` to this actor once after `$delay`.
     *
     * Returns a `Cancellable` that can be used to abort the timer before it fires.
     *
     * @param T $message
     */
    public function scheduleOnce(Duration $delay, object $message): Cancellable;

    /**
     * Deliver `$message` to this actor on a fixed interval.
     *
     * The first delivery is after `$initialDelay`; subsequent deliveries repeat
     * every `$interval`. Returns a `Cancellable` to stop the timer.
     *
     * @param T $message
     */
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, object $message): Cancellable;

    /**
     * Stash the current message for later processing.
     *
     * The message is held in an internal buffer and will be re-delivered to the
     * actor's mailbox when `unstashAll()` is called.
     */
    public function stash(): void;

    /**
     * Re-enqueue all stashed messages at the front of the mailbox.
     *
     * Messages are replayed in the order they were stashed.
     */
    public function unstashAll(): void;

    /** Return the PSR-3 logger associated with this actor. */
    public function log(): LoggerInterface;

    /** @return ?ActorRef<object> */
    public function sender(): ?ActorRef;

    /**
     * Reply to the sender of the current message.
     * Only works for messages received via ask() — throws for regular tell().
     *
     * @throws NoSenderException If no sender on current message
     */
    public function reply(object $message): void;

    /**
     * Configure a receive-timeout: if no user message arrives within $timeout
     * after the last user-message dispatch, the actor receives a
     * Monadial\Nexus\Core\Lifecycle\ReceiveTimeout signal via onSignal().
     *
     * Call with null to cancel. Re-calling with a new Duration replaces the
     * current setting; the first user message after the call uses the new
     * timeout.
     */
    public function setReceiveTimeout(?Duration $timeout): void;

    /**
     * Spawn a background task bound to this actor's lifecycle.
     *
     * The task closure receives a {@see TaskContext} for cooperative cancellation
     * and sending messages back to the parent actor. All spawned tasks are
     * automatically cancelled when the actor stops.
     *
     * @param Closure(TaskContext): void $task
     */
    public function spawnTask(Closure $task): Cancellable;
}
