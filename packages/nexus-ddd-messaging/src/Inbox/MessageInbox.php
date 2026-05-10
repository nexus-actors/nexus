<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Inbox;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;

/**
 * @psalm-api
 *
 * Consumer-side dedup gate. Scoped per (handler, message-id) — the same
 * message id may legitimately be processed by multiple distinct handlers.
 */
interface MessageInbox
{
    /**
     * Returns true if the (handler, messageId) pair has not been processed
     * before AND atomically reserves the id; false if already processed.
     *
     * @param class-string $handlerClass
     */
    public function tryReserve(string $handlerClass, MessageId $messageId): bool;

    /**
     * Mark a previously-reserved (handler, messageId) as fully completed.
     * Verb pairs with the upcoming IdempotencyStore::markCompleted (spec
     * section 13.1) — "completed" is the dedup-state transition, not a
     * statement about handler-side work. Persistent implementations record
     * the timestamp; in-memory may no-op because the reservation itself is
     * already permanent in the local map.
     *
     * @param class-string $handlerClass
     * @param Option<DateTimeImmutable> $at
     */
    public function markCompleted(string $handlerClass, MessageId $messageId, Option $at): void;

    /**
     * Release a reservation (called on handler failure / rollback) so the
     * next redelivery can retry.
     *
     * @param class-string $handlerClass
     */
    public function release(string $handlerClass, MessageId $messageId): void;
}
