<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value;

use DateTimeImmutable;
use DateTimeInterface;
use Override;

/**
 * @psalm-api
 *
 * @extends Value<DateTimeImmutable>
 */
abstract readonly class DateTimeValue extends Value
{
    public function __construct(DateTimeImmutable $value)
    {
        parent::__construct($value);
    }

    #[Override]
    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->value->format(DateTimeInterface::ATOM) === $other->value->format(DateTimeInterface::ATOM);
    }

    public function __toString(): string
    {
        return $this->value->format(DateTimeInterface::ATOM);
    }
}
