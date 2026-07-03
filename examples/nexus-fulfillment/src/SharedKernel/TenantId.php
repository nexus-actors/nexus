<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

use InvalidArgumentException;

use function preg_match;

/**
 * Identifies a tenant. Every command, event, and entity address in the
 * system carries one — tenants are isolated at the actor level.
 */
final readonly class TenantId
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid tenant id: '{$value}'");
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
