<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use function implode;
use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when two or more routing strategies resolve the same command
 * class to different bus names. Routing must be unambiguous at boot.
 */
final class DuplicateRoutingException extends BusBootException
{
    /**
     * @param list<string> $resolvedTo each entry formatted "<strategyClass>: <busName>"
     */
    public static function for(string $commandClass, array $resolvedTo): self
    {
        return new self(sprintf(
            'Command `%s` resolves to multiple buses: [%s].',
            $commandClass,
            implode(', ', $resolvedTo),
        ));
    }
}
