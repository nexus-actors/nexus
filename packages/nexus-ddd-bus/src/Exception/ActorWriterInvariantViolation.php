<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;

use function sprintf;

/**
 * @psalm-api
 *
 * Raised when the single-writer principle is breached inside the actor
 * profile — typically an optimistic-lock collision observed by the
 * actor's persistence engine where the actor itself is the sole expected
 * writer. Terminal because it indicates a wiring fault, not a transient
 * conflict to retry.
 */
final class ActorWriterInvariantViolation extends BusRuntimeException implements TerminalFailure
{
    public static function forOptimisticLock(
        string $aggregateClass,
        string $aggregateId,
        int $expectedVersion,
        int $actualVersion,
    ): self {
        return new self(sprintf(
            'Actor-writer invariant violated on %s(%s): expected version %d, found %d.',
            $aggregateClass,
            $aggregateId,
            $expectedVersion,
            $actualVersion,
        ));
    }
}
