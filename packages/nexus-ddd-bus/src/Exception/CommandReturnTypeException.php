<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when a command handler declares a return type other than `void`.
 * Surfaces at first dispatch (not at boot), so it lives under
 * `BusBootException` only because it's an authoring-time wiring error.
 */
final class CommandReturnTypeException extends BusBootException
{
    public static function for(string $handlerClass, string $actualReturn): self
    {
        return new self(sprintf(
            'Command handler `%s` must return `void`, declared `%s`.',
            $handlerClass,
            $actualReturn,
        ));
    }
}
