<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use InvalidArgumentException;

use function sprintf;
use function strlen;

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
    public const int MAX_LENGTH = 256;

    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('IdempotencyKey value cannot be empty.');
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'IdempotencyKey value length %d exceeds maximum %d bytes.',
                strlen($value),
                self::MAX_LENGTH,
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
