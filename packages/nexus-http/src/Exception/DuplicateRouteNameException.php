<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown at route registration time when two routes share the same name.
 * Registration order matters; the second registration triggers this.
 */
final class DuplicateRouteNameException extends NexusException
{
    public function __construct(string $name)
    {
        parent::__construct("Route name '{$name}' is already registered.");
    }
}
