<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * Thrown when registering a pending ask exceeds the capacity.
 *
 * @psalm-api
 */
final class AskCapacityExceededException extends NexusException
{
    public function __construct(int $maxPending, int $currentCount,) {
        parent::__construct(
            \sprintf(
                'Ask capacity exceeded: max %d, current %d',
                $maxPending,
                $currentCount,
            ),
        );
    }
}
