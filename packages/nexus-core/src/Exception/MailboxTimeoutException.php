<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;

/** @psalm-api */
final class MailboxTimeoutException extends MailboxException
{
    public function __construct(public readonly ActorPath $actor, public readonly Duration $timeout)
    {
        parent::__construct("Mailbox dequeue for {$actor} timed out after {$timeout}");
    }
}
