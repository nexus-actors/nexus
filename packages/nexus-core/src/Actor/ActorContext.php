<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\NoSenderException;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Psr\Log\LoggerInterface;

/**
 * @psalm-api
 *
 * @template T of object
 */
interface ActorContext
{
    /** @return ActorRef<T> */
    public function self(): ActorRef;

    /** @return Option<ActorRef<object>> */
    public function parent(): Option;

    public function path(): ActorPath;

    /**
     * @template C of object
     * @param Props<C> $props
     * @return ActorRef<C>
     * @throws ActorInitializationException
     */
    public function spawn(Props $props, string $name): ActorRef;

    /** @param ActorRef<object> $child */
    public function stop(ActorRef $child): void;

    /** @return Option<ActorRef<object>> */
    public function child(string $name): Option;

    /** @return array<string, ActorRef<object>> */
    public function children(): array;

    /** @param ActorRef<object> $target */
    public function watch(ActorRef $target): void;

    /** @param ActorRef<object> $target */
    public function unwatch(ActorRef $target): void;

    /** @param T $message */
    public function scheduleOnce(Duration $delay, object $message): Cancellable;

    /** @param T $message */
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, object $message): Cancellable;

    public function stash(): void;

    public function unstashAll(): void;

    public function log(): LoggerInterface;

    /** @return Option<ActorRef<object>> */
    public function sender(): Option;

    /**
     * Send a message to another actor, propagating the current correlation context.
     *
     * Use this instead of $ref->tell() when sending from inside a message handler —
     * the outgoing envelope will carry the same correlationId and set causationId
     * to the current message's requestId, preserving the trace thread.
     *
     * Outside a handler (e.g. in setup callbacks) falls back to a fresh root envelope.
     *
     * @param ActorRef<object> $ref
     */
    public function tell(ActorRef $ref, object $message): void;

    /**
     * Reply to the sender of the current message, propagating the current correlation context.
     *
     * @throws NoSenderException If no sender on current message
     */
    public function reply(object $message): void;

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
