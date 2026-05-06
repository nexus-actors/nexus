<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Minimal UUID-backed Identifier. Will be enriched in Task 10 to extend WrappedValue.
 */
readonly class UuidValue implements Identifier // phpcs:ignore SlevomatCodingStandard.Classes.RequireAbstractOrFinal.ClassNeitherAbstractNorFinal
{
    final public function __construct(private string $value)
    {
        if (! Uuid::isValid($value)) {
            throw InvalidIdentifierException::malformed(static::class, $value, 'not a valid UUID');
        }
    }

    #[\Override]
    public function value(): string
    {
        return $this->value;
    }

    #[\Override]
    public function equals(Identifier $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }

    #[\Override]
    public static function fromString(string $value): static
    {
        return new static($value);
    }
}
