<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;

/** @psalm-api */
final class MailboxClosedException extends MailboxException
{
    public function __construct(public readonly ActorPath $actor,) {
        parent::__construct("Mailbox for {$actor} is closed");
    }
}
