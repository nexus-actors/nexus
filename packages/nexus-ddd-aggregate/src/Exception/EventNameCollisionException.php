<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Exception;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;

/**
 * @psalm-api
 *
 * Thrown at boot time when the `EventNameRegistry` discovers two distinct
 * `DomainEvent` classes claiming the same `(eventName, schemaVersion)`
 * tuple. Each tuple MUST be globally unique so that wire-format payloads
 * can deserialize back to exactly one PHP class.
 *
 * Framework-wiring fault — caught at the registry build step before any
 * traffic flows. The fix is to rename one of the colliding event classes
 * (or bump its `schemaVersion`), not to catch this exception.
 */
final class EventNameCollisionException extends NexusDddException
{
    /**
     * @param non-empty-string $eventName
     * @param non-empty-string $classA
     * @param non-empty-string $classB
     */
    public static function between(string $eventName, string $classA, string $classB): self
    {
        return new self(sprintf(
            'Event name %s is declared by both %s and %s. Each (eventName, schemaVersion) MUST be unique across all DomainEvent classes.',
            $eventName,
            $classA,
            $classB,
        ));
    }
}
