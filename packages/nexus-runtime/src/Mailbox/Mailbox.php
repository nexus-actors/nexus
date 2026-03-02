<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Mailbox;

use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxTimeoutException;
use NoDiscard;

/**
 * @psalm-api
 * @template T of object
 */
interface Mailbox
{
    /**
     * @throws MailboxClosedException
     */
    /**
     * @param T $message
     */
    #[NoDiscard]
    public function enqueue(object $message): EnqueueResult;

    /** @return T|null */
    public function dequeue(): mixed;

    /**
     * @throws MailboxClosedException
     * @throws MailboxTimeoutException
     */
    /** @return T */
    public function dequeueBlocking(Duration $timeout): object;

    public function count(): int;

    public function isFull(): bool;

    public function isEmpty(): bool;

    public function close(): void;
}
