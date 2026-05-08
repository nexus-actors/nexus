<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Abstract UUID-backed identifier base. Domain code defines concrete identifiers
 * by extending this class:
 *
 *   final readonly class OrderId extends UuidValue {}
 *
 * Direct instantiation is impossible — `UuidValue` itself is abstract.
 *
 * @extends WrappedValue<string>
 */
abstract readonly class UuidValue extends WrappedValue implements Identifier
{
    final public function __construct(string $value)
    {
        if (! Uuid::isValid($value)) {
            throw InvalidIdentifierException::malformed(static::class, $value, 'not a valid UUID');
        }

        parent::__construct($value);
    }

    /**
     * Class-level `@psalm-immutable` keeps this implicitly pure.
     */
    #[Override]
    public function value(): string
    {
        return $this->getValue();
    }

    #[Override]
    public static function fromString(string $value): static
    {
        return new static($value);
    }
}
