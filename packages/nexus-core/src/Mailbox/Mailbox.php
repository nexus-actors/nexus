<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Mailbox;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\MailboxClosedException;

interface Mailbox
{
    /**
     * @throws MailboxClosedException
     */
    #[\NoDiscard]
    public function enqueue(Envelope $envelope): EnqueueResult;

    /** @return Option<Envelope> */
    public function dequeue(): Option;

    /**
     * @throws MailboxClosedException
     */
    public function dequeueBlocking(Duration $timeout): Envelope;

    public function count(): int;
    public function isFull(): bool;
    public function isEmpty(): bool;
    public function close(): void;
}
