<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;

/** @psalm-api */
final class MailboxOverflowException extends MailboxException
{
    public function __construct(
        public readonly ActorPath $actor,
        public readonly int $capacity,
        public readonly OverflowStrategy $strategy,
    ) {
        parent::__construct("Mailbox for {$actor} overflowed (capacity: {$capacity}, strategy: {$strategy->value})");
    }
}
