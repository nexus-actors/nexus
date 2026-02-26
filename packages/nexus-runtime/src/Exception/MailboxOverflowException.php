<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Exception;

use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

/** @psalm-api */
final class MailboxOverflowException extends MailboxException
{
    public function __construct(public readonly int $capacity, public readonly OverflowStrategy $strategy)
    {
        parent::__construct("Mailbox overflowed (capacity: {$capacity}, strategy: {$strategy->value})");
    }
}
