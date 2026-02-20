<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

/**
 * @psalm-api
 *
 * Base class for class-based actors with lifecycle hooks.
 *
 * Extends ActorHandler with optional onPreStart() and onPostStop() hooks.
 * Override these methods to perform initialization or cleanup.
 *
 * @template T of object
 * @implements ActorHandler<T>
 */
// phpcs:ignore SlevomatCodingStandard.Classes.SuperfluousAbstractClassNaming -- canonical base class name
abstract class AbstractActor implements ActorHandler
{
    /**
     * Called after the actor starts. Override for initialization logic.
     *
     * @param ActorContext<T> $ctx
     * @psalm-suppress PossiblyUnusedParam $ctx is available for subclass overrides
     */
    public function onPreStart(ActorContext $ctx): void
    {
        // Default no-op; override in subclass for initialization logic.
    }

    /**
     * Called before the actor stops. Override for cleanup logic.
     *
     * @param ActorContext<T> $ctx
     * @psalm-suppress PossiblyUnusedParam $ctx is available for subclass overrides
     */
    public function onPostStop(ActorContext $ctx): void
    {
        // Default no-op; override in subclass for cleanup logic.
    }
}
