<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

use InvalidArgumentException;

use function preg_match;

/**
 * Stock-keeping unit — the catalog identity of a sellable item.
 */
final readonly class Sku
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{2,31}$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid SKU: '{$value}'");
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
