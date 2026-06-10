<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown when a handler/scope requests an actor by name that was not
 * registered with HttpApp, or when a per-request scope is used after dispose.
 */
final class UnknownActorException extends NexusException
{
    public function __construct(string $name)
    {
        parent::__construct("No actor registered with name '{$name}'.");
    }
}
