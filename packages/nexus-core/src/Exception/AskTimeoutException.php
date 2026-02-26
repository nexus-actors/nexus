<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\FutureTimeoutException;
use Throwable;

/** @psalm-api */
final class AskTimeoutException extends ActorException implements FutureTimeoutException
{
    public function __construct(
        public readonly ActorPath $target,
        public readonly Duration $timeout,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Ask to {$target} timed out after {$timeout}", previous: $previous);
    }
}
