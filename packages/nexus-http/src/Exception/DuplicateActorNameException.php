<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown at boot time when two actors are registered under the same name.
 * Registration order matters; the second registration triggers this.
 */
final class DuplicateActorNameException extends NexusException
{
    public function __construct(string $name)
    {
        parent::__construct("Actor '{$name}' is already registered with HttpApp.");
    }
}
