<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Runtime\Exception\FutureException;
use RuntimeException;
use Throwable;

/**
 * Wraps an exception thrown inside an actor handler so it can propagate
 * back to ask() callers via FutureSlot::fail() instead of timing out.
 *
 * @psalm-api
 */
final class ActorHandlerException extends RuntimeException implements FutureException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
