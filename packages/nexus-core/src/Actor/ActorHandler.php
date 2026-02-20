<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

/**
 * @psalm-api
 *
 * Minimal contract for class-based actors.
 *
 * Implement this interface to define an actor as a class with a handle() method
 * instead of using Behavior closures directly. The class can then be spawned via
 * Props::fromFactory() or Props::fromContainer().
 *
 * @template T of object
 */
interface ActorHandler
{
    /**
     * Handle an incoming message and return the next behavior.
     *
     * @param ActorContext<T> $ctx
     * @param T $message
     * @return Behavior<T>
     */
    public function handle(ActorContext $ctx, object $message): Behavior;
}
