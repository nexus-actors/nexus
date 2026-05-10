<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when a command is annotated with `#[Validate]` but no
 * `Validator` is registered for that command class.
 */
final class MissingValidatorException extends BusBootException
{
    public static function for(string $commandClass): self
    {
        return new self(sprintf('No validator registered for command `%s`.', $commandClass));
    }
}
