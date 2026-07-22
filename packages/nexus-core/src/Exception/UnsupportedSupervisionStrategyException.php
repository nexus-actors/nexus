<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Supervision\StrategyType;

use function sprintf;

/**
 * Thrown at spawn when an actor is configured with a supervision strategy that the
 * current runtime does not honor, rather than silently degrading to a different one.
 *
 * `all-for-one` supervision requires the parent to restart a failed child's siblings,
 * which the self-supervision model does not support; it is rejected so callers are not
 * misled into believing sibling restarts happen.
 *
 * @psalm-api
 */
final class UnsupportedSupervisionStrategyException extends NexusException
{
    public static function forStrategy(StrategyType $type): self
    {
        return new self(sprintf(
            'Supervision strategy "%s" is not supported. Use oneForOne() or exponentialBackoff(); '
            . 'all-for-one sibling restarts require parent-managed supervision, which this runtime does not provide.',
            $type->value,
        ));
    }
}
