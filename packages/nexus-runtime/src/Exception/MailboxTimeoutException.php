<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Exception;

use Monadial\Nexus\Runtime\Duration;

/** @psalm-api */
final class MailboxTimeoutException extends MailboxException
{
    public function __construct(public readonly Duration $timeout)
    {
        parent::__construct("Mailbox dequeue timed out after {$timeout}");
    }
}
