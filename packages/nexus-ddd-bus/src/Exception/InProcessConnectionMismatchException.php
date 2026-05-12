<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when two bound classes touched in the same transaction resolve to
 * different DBAL/Doctrine connection names — the in-process same-DB
 * invariant requires all bound classes in a unit-of-work to share one
 * connection.
 */
final class InProcessConnectionMismatchException extends BusRuntimeException
{
    public static function for(string $boundClass, string $expectedConnection, string $actualConnection): self
    {
        return new self(sprintf(
            'Bound class `%s` resolved to connection `%s`, expected `%s`.',
            $boundClass,
            $actualConnection,
            $expectedConnection,
        ));
    }
}
