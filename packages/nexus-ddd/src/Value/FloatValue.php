<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value;

use Override;

/**
 * @psalm-api
 *
 * @extends Value<float>
 */
abstract readonly class FloatValue extends Value
{
    public function __construct(float $value)
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
