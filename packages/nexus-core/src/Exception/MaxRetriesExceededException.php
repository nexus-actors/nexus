<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;
use Throwable;

/** @psalm-api */
final class MaxRetriesExceededException extends NexusException
{
    public function __construct(
        public readonly ActorPath $child,
        public readonly int $maxRetries,
        public readonly Duration $window,
        public readonly Throwable $lastFailure,
    ) {
        parent::__construct(
            "Actor {$child} exceeded max retries ({$maxRetries}) within {$window}",
            previous: $lastFailure,
        );
    }
}
