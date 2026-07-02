<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Lifecycle;

/**
 * Marker interface for actor lifecycle signals.
 *
 * Signals are out-of-band notifications delivered by the actor runtime to
 * inform a behavior about lifecycle events such as startup, shutdown, or the
 * termination of a watched peer. They are never sent by user code directly —
 * the runtime produces them automatically and dispatches them through the
 * handler registered with {@see Behavior::onSignal()}.
 *
 * The four built-in signals are:
 * - {@see PreStart}   — emitted before the first user message is processed.
 * - {@see PostStop}   — emitted after the actor has stopped and all children
 *                       have been shut down.
 * - {@see Terminated} — emitted when a watched actor terminates; carries the
 *                       terminated {@see ActorRef}.
 * - {@see ChildFailed} — emitted when a direct child throws an unhandled
 *                        exception; carries the child ref and the throwable.
 *
 * Example — reacting to lifecycle events inside a behavior:
 * ```php
 * $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
 *     ->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
 *         if ($signal instanceof PreStart) {
 *             $ctx->log()->info('actor started');
 *         }
 *         if ($signal instanceof PostStop) {
 *             $ctx->log()->info('actor stopped — release resources here');
 *         }
 *         return Behavior::same();
 *     });
 * ```
 *
 * @see Behavior::onSignal() for attaching a signal handler to a behavior
 * @see PreStart
 * @see PostStop
 * @see Terminated
 * @see ChildFailed
 *
 * @psalm-api
 */
interface Signal {}
