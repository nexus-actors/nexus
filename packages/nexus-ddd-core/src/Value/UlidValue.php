<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;
use Symfony\Component\Uid\Ulid;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Abstract ULID-backed identifier base. Domain code defines concrete identifiers
 * by extending this class:
 *
 *   final readonly class OrderId extends UlidValue {}
 *   final readonly class CustomerId extends UlidValue {}
 *
 * Direct instantiation is impossible — `UlidValue` itself is abstract.
 *
 * @extends WrappedValue<string>
 */
abstract readonly class UlidValue extends WrappedValue implements Identifier
{
    final public function __construct(string $value)
    {
        if (! Ulid::isValid($value)) {
            throw InvalidIdentifierException::malformed(static::class, $value, 'not a valid ULID');
        }

        parent::__construct($value);
    }

    /**
     * Identifier::value() — public accessor for canonical-string storage form.
     * Delegates to inherited protected WrappedValue::getValue(). Class-level
     * `@psalm-immutable` keeps this implicitly pure.
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

    // equals(object $other): bool — inherited from WrappedValue; satisfies Identifier::equals(Identifier)
    // because object is a supertype of Identifier (parameter contravariance).
}
