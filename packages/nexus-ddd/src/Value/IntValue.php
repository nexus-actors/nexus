<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value;

use Override;

/**
 * @psalm-api
 *
 * @extends Value<int>
 */
abstract readonly class IntValue extends Value
{
    public function __construct(int $value)
    {
        parent::__construct($value);
    }

    #[Override]
    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
