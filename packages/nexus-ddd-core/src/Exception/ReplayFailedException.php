<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

use Throwable;

/** @psalm-api */
final class ReplayFailedException extends NexusDddException
{
    public function __construct(
        public readonly int $eventsApplied,
        public readonly object $failingEvent,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'Replay failed after %d events while applying %s: %s',
                $eventsApplied,
                $failingEvent::class,
                $previous->getMessage(),
            ),
            previous: $previous,
        );
    }
}
