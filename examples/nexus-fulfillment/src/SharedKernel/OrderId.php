<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

/**
 * Order identity — a ULID string, sortable by creation time.
 */
final readonly class OrderId
{
    public function __construct(public string $value)
    {
        if (!Ulid::isValid($value)) {
            throw new InvalidArgumentException("Invalid order id: '{$value}'");
        }
    }

    public static function generate(): self
    {
        return new self((string) new Ulid());
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
