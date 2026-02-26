<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Exception;

/** @psalm-api */
final class MailboxClosedException extends MailboxException
{
    public function __construct()
    {
        parent::__construct('Mailbox is closed');
    }
}
