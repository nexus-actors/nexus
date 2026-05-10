<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when two aggregates touched in the same transaction resolve to
 * different DBAL/Doctrine connection names — the in-process same-DB
 * invariant requires all aggregates in a unit-of-work to share one
 * connection.
 */
final class InProcessConnectionMismatchException extends BusRuntimeException
{
    public static function for(string $aggregateClass, string $expectedConnection, string $actualConnection): self
    {
        return new self(sprintf(
            'Aggregate `%s` resolved to connection `%s`, expected `%s`.',
            $aggregateClass,
            $actualConnection,
            $expectedConnection,
        ));
    }
}
