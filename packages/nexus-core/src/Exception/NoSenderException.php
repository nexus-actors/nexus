<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

/**
 * @psalm-api
 *
 * Thrown when reply() is called on a message that has no sender.
 * This happens when replying to a regular tell() message (not an ask).
 */
final class NoSenderException extends ActorException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
