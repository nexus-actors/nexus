<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value;

use Monadial\Nexus\Ddd\Value\Exception\InvalidUlid;
use Override;
use Symfony\Component\Uid\Ulid;

/**
 * @psalm-api
 *
 * @extends Value<string>
 */
abstract readonly class UlidValue extends Value
{
    public function __construct(string $value)
    {
        if (!Ulid::isValid($value)) {
            throw new InvalidUlid($value);
        }

        parent::__construct($value);
    }

    #[Override]
    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->value === $other->value;
    }

    /** @psalm-suppress UnsafeInstantiation */
    public static function generate(): static
    {
        return new static((string) new Ulid());
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
