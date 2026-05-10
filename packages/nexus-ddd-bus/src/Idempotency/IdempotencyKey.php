<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use InvalidArgumentException;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Application-supplied idempotency key. Carried on the message via
 * `#[IdempotencyKey]` (Phase 8 attribute) and used by the bus's two-phase
 * idempotency middleware to gate redelivery: first reserve via
 * `IdempotencyStore::tryReserve`, then commit inside the handler TX via
 * `IdempotencyStore::markCompleted` (or release on failure).
 */
final readonly class IdempotencyKey
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('IdempotencyKey value cannot be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
