<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use function implode;
use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when a routing strategy resolves a command to a bus name that
 * was never registered with the `BusRegistry`.
 */
final class BusNameNotRegisteredException extends BusBootException
{
    /**
     * @param list<string> $registered
     */
    public static function for(string $busName, array $registered): self
    {
        return new self(sprintf(
            'Bus `%s` is not registered. Registered buses: [%s].',
            $busName,
            implode(', ', $registered),
        ));
    }
}
