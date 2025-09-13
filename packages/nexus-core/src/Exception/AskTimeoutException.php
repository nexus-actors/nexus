<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;
use Throwable;

/** @psalm-api */
final class AskTimeoutException extends ActorException
{
    public function __construct(
        public readonly ActorPath $target,
        public readonly Duration $timeout,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Ask to {$target} timed out after {$timeout}", previous: $previous);
    }
}
