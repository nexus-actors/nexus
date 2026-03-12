<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value;

use Override;
use UnitEnum;

/**
 * @psalm-api
 *
 * @extends Value<UnitEnum>
 */
abstract readonly class EnumValue extends Value
{
    public function __construct(UnitEnum $value)
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
        return $this->value->name;
    }
}
