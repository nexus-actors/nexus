<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown at compile time when a constructor parameter requests a per-request
 * actor. Per-request actors are only available inside the
 * handler/middleware invocation method.
 */
final class PerRequestActorInConstructorException extends NexusException
{
    public function __construct(string $class, string $param, string $actorName)
    {
        parent::__construct(
            "{$class}::__construct(\${$param}) injects per-request actor "
            . "'{$actorName}'. Per-request actors can only be injected into "
            . 'the handler/middleware invocation method, not the constructor.',
        );
    }
}
