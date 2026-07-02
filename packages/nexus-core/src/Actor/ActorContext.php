<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\Tracer;
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
    /**
     * Return the type-safe reference to this actor itself.
     *
     * Use this to hand out a back-reference to other actors (e.g. as a `replyTo`
     * field on outgoing messages) so they can send messages back to this actor.
     *
     * @return ActorRef<T> Reference typed to this actor's message protocol
     */
    public function self(): ActorRef;

    /**
     * Return the supervising parent actor, or null if this is a root actor.
     *
     * @return ?ActorRef<object> Parent reference, or null for root-level actors
     */
    public function parent(): ?ActorRef;

    /**
     * Return the actor's canonical path in the supervision tree.
     *
     * @return ActorPath Hierarchical path such as `/user/orders/worker-1`
     */
    public function path(): ActorPath;

    /**
     * Spawn a named child actor under this actor in the supervision tree.
     *
     * The child runs concurrently and is supervised by this actor according to
     * the supervision strategy configured on its `Props`. Names must be unique
     * among live children — a dead child with the same name is pruned and
     * replaced transparently.
     *
     * @template C of object
     *
     * @param Props<C> $props Immutable spawn configuration (behavior, mailbox, supervision)
     * @param string $name Child name, unique within this actor's live children
     *
     * @return ActorRef<C> Reference to the newly spawned child
     *
     * @throws \Monadial\Nexus\Core\Exception\ActorInitializationException When the child fails to start
     * @throws \Monadial\Nexus\Core\Exception\ActorNameExistsException When a live child with `$name` already exists
     */
    public function spawn(Props $props, string $name): ActorRef;

    /**
     * Gracefully stop a direct child actor.
     *
     * The child finishes processing its current message, then receives a
     * `PostStop` signal before being removed from the supervision tree.
     *
     * @param ActorRef<object> $child Reference to the child actor to stop
     */
    public function stop(ActorRef $child): void;

    /**
     * Return the child actor with the given name, or null if it does not exist.
     *
     * @param string $name Child name as passed to `spawn()`
     *
     * @return ?ActorRef<object> Child reference, or null if no live child has that name
     */
    public function child(string $name): ?ActorRef;

    /**
     * Return all live direct children keyed by name.
     *
     * @return array<string, ActorRef<object>> Map of child name to actor reference
     */
    public function children(): array;

    /**
     * Subscribe to termination notifications for `$target`.
     *
     * When `$target` stops, this actor receives a `Terminated` signal via its
     * `onSignal()` handler.
     *
     * @param ActorRef<object> $target Actor reference to watch for termination
     */
    public function watch(ActorRef $target): void;

    /**
     * Cancel a previously registered death-watch subscription.
     *
     * @param ActorRef<object> $target Previously-watched reference to stop watching
     */
    public function unwatch(ActorRef $target): void;

    /**
     * Deliver `$message` to this actor once after `$delay`.
     *
     * Returns a `Cancellable` that can be used to abort the timer before it fires.
     *
     * @param Duration $delay Wall-clock delay before the message is enqueued
     * @param T $message Message to deliver to this actor's own mailbox
     *
     * @return Cancellable Handle that aborts the pending delivery when cancelled
     */
    public function scheduleOnce(Duration $delay, object $message): Cancellable;

    /**
     * Deliver `$message` to this actor on a fixed interval.
     *
     * The first delivery is after `$initialDelay`; subsequent deliveries repeat
     * every `$interval`. Returns a `Cancellable` to stop the timer.
     *
     * @param Duration $initialDelay Delay before the first delivery
     * @param Duration $interval Period between subsequent deliveries
     * @param T $message Message to deliver to this actor's own mailbox on each tick
     *
     * @return Cancellable Handle that stops further deliveries when cancelled
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

    /**
     * Return the PSR-3 logger associated with this actor.
     *
     * @return LoggerInterface Logger pre-bound with this actor's path as context
     */
    public function log(): LoggerInterface;

    /**
     * Return the sender of the message currently being handled, if any.
     *
     * Populated for messages received via `ask()`; null for plain `tell()`
     * dispatches that did not include a reply-to.
     *
     * @return ?ActorRef<object> Sender reference, or null when none was attached
     */
    public function sender(): ?ActorRef;

    /**
     * Reply to the sender of the current message.
     *
     * Only works for messages received via ask() — throws for regular tell().
     *
     * @param object $message Reply payload to deliver to the original sender
     *
     * @throws \Monadial\Nexus\Core\Exception\NoSenderException If no sender on current message
     */
    public function reply(object $message): void;

    /**
     * Configure a receive-timeout for this actor.
     *
     * If no user message arrives within `$timeout` after the last user-message
     * dispatch, the actor receives a `Monadial\Nexus\Core\Lifecycle\ReceiveTimeout`
     * signal via `onSignal()`. System messages do not reset the timer. Call with
     * null to cancel; re-calling with a new Duration replaces the current
     * setting and takes effect on the first subsequent user message.
     *
     * @param ?Duration $timeout Inactivity window, or null to disable the timeout
     */
    public function setReceiveTimeout(?Duration $timeout): void;

    /**
     * Spawn a background task bound to this actor's lifecycle.
     *
     * The task closure receives a {@see TaskContext} for cooperative cancellation
     * and sending messages back to the parent actor. All spawned tasks are
     * automatically cancelled when the actor stops.
     *
     * @param Closure(TaskContext): void $task Task body invoked with a per-task cancellation/reply context
     *
     * @return Cancellable Handle that cancels the task when invoked
     */
    public function spawnTask(Closure $task): Cancellable;

    /**
     * Tracer for creating custom spans from within a handler; child of the
     * current message span. No-op when observability is disabled.
     */
    public function tracer(): Tracer;

    /**
     * Meter for recording custom metrics from within a handler. No-op when
     * observability is disabled.
     */
    public function meter(): Meter;

    /**
     * The span for the message currently being handled (a no-op span outside a
     * user-message handler). Use to add attributes/events to the active span.
     */
    public function currentSpan(): Span;
}
