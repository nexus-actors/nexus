<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Inbox;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Override;

/**
 * @psalm-api
 *
 * In-memory dedup map. Per-process; reservations are lost on restart.
 * TESTS-ONLY (and single-process Fiber-only). Production deployments
 * MUST use a persistent inbox (Redis SET-NX, DB unique index, etc.) so
 * dedup survives a crash between reservation and processing.
 */
final class InMemoryMessageInbox implements MessageInbox
{
    /** @var array<string, array<string, true>> handlerClass => messageId.value() => true */
    private array $reservations = [];

    /**
     * @param class-string $handlerClass
     */
    #[Override]
    public function tryReserve(string $handlerClass, MessageId $messageId): bool
    {
        $key = $messageId->value();

        if (isset($this->reservations[$handlerClass][$key])) {
            return false;
        }

        $this->reservations[$handlerClass][$key] = true;

        return true;
    }

    /**
     * @param class-string $handlerClass
     * @param Option<DateTimeImmutable> $at
     */
    #[Override]
    public function markProcessed(string $handlerClass, MessageId $messageId, Option $at): void
    {
    }

    /**
     * @param class-string $handlerClass
     */
    #[Override]
    public function release(string $handlerClass, MessageId $messageId): void
    {
        unset($this->reservations[$handlerClass][$messageId->value()]);
    }
}
