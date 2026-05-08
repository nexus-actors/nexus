<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Exception;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;

/**
 * @psalm-api
 *
 * Thrown when a single command/event handler attempts to persist a second
 * aggregate within the same transaction. The bus middleware enforces the
 * one-aggregate-per-transaction rule and raises this when a handler tries
 * to mutate two aggregate roots in one unit of work.
 *
 * Framework-wiring fault — handler misbehavior the team made while adopting
 * the DDD package. Not a domain rule violation. The fix is process managers
 * + outbox for cross-aggregate coordination, not catching this exception.
 */
final class MultiAggregateTransactionException extends NexusDddException
{
    /**
     * @param non-empty-string $firstAggregateClass
     * @param non-empty-string $firstAggregateId
     * @param non-empty-string $secondAggregateClass
     * @param non-empty-string $secondAggregateId
     */
    public static function secondAggregateInTransaction(
        string $firstAggregateClass,
        string $firstAggregateId,
        string $secondAggregateClass,
        string $secondAggregateId,
    ): self {
        return new self(sprintf(
            'A handler attempted to persist a second aggregate within the same transaction: first %s(%s); second %s(%s). One-aggregate-per-transaction rule violated. Use process managers + outbox for cross-aggregate coordination.',
            $firstAggregateClass,
            $firstAggregateId,
            $secondAggregateClass,
            $secondAggregateId,
        ));
    }
}
