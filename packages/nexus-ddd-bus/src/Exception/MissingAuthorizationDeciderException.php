<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when a command is annotated with `#[Authorize]` but no
 * `AuthorizationDecider` is registered for that command class.
 */
final class MissingAuthorizationDeciderException extends BusBootException
{
    public static function for(string $commandClass): self
    {
        return new self(sprintf('No authorization decider registered for command `%s`.', $commandClass));
    }
}
