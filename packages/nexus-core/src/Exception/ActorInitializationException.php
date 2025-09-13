<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;
use Throwable;

/** @psalm-api */
final class ActorInitializationException extends ActorException
{
    public function __construct(
        public readonly ActorPath $actor,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Actor {$actor} failed to initialize: {$reason}", previous: $previous);
    }
}
