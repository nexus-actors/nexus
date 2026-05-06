<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Symfony\Component\Uid\Ulid;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<string>
 */
readonly class UlidValue extends WrappedValue implements Identifier // phpcs:ignore SlevomatCodingStandard.Classes.RequireAbstractOrFinal.ClassNeitherAbstractNorFinal
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
     * Delegates to inherited protected WrappedValue::getValue().
     *
     * @psalm-pure
     */
    #[\Override]
    public function value(): string
    {
        /** @var string $v */
        $v = $this->getValue();

        return $v;
    }

    #[\Override]
    public static function fromString(string $value): static
    {
        return new static($value);
    }

    // equals(object $other): bool — inherited from WrappedValue; satisfies Identifier::equals(Identifier)
    // because object is a supertype of Identifier (parameter contravariance).
}
