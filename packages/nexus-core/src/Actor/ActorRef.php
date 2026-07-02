<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use NoDiscard;

/**
 * Type-safe reference to an actor — the only handle user code holds.
 *
 * ActorRef is the public face of every actor. You obtain a ref from
 * ActorSystem::spawn() or ActorContext::spawn() and use it to send messages
 * without any knowledge of where or how the actor is running (local fiber,
 * Swoole coroutine, remote worker thread, or dead-letter sink).
 *
 * Concrete implementations include LocalActorRef (in-process), WorkerActorRef
 * (cross-thread in a worker pool), and DeadLetterRef (null object for stopped
 * or non-existent actors). User code should only depend on this interface.
 *
 * Example:
 * ```php
 * $ref = $system->spawn(Props::fromBehavior($behavior), 'counter');
 *
 * // Fire-and-forget
 * $ref->tell(new Increment());
 *
 * // Request-response (suspends the current fiber until the reply arrives)
 * $count = $ref->ask(new GetCount(), Duration::seconds(5))->await();
 * ```
 *
 * @see ActorSystem::spawn() to obtain an ActorRef
 * @see ActorContext::spawn() to spawn child actors
 * @see Props for configuring how the actor is spawned
 *
 * @psalm-api
 *
 * @template T of object
 */
interface ActorRef
{
    /**
     * Send a message to the actor without waiting for a reply (fire-and-forget).
     *
     * The message is enqueued in the actor's mailbox and processed asynchronously.
     * This method never blocks and always returns immediately.
     *
     * @param T $message
     */
    public function tell(object $message): void;

    /**
     * Send a message and get a Future for the reply.
     *
     * The message is sent immediately (eager). The reply is received
     * via a lightweight FutureSlot. The handler replies with ctx->reply().
     *
     * @template R of object
     * @param T $message
     * @return Future<R>
     * @throws AskTimeoutException
     */
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future;

    /**
     * Return the hierarchical path that uniquely identifies this actor in the system.
     *
     * Paths have the form /user/parent/child and are stable for the lifetime of
     * the actor. They appear in log output and are used as routing keys in the
     * worker pool and cluster layers.
     */
    public function path(): ActorPath;

    /**
     * Return true if the actor is still running and able to receive messages.
     *
     * Returns false once the actor has stopped or its mailbox has been closed.
     * Messages sent to a stopped actor are forwarded to dead letters.
     */
    public function isAlive(): bool;
}
