<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
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
