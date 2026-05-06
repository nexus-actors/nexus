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
 * @extends WrappedValue<string>
 */
readonly class UuidValue extends WrappedValue implements Identifier // phpcs:ignore SlevomatCodingStandard.Classes.RequireAbstractOrFinal.ClassNeitherAbstractNorFinal
{
    final public function __construct(string $value)
    {
        if (! Uuid::isValid($value)) {
            throw InvalidIdentifierException::malformed(static::class, $value, 'not a valid UUID');
        }
        parent::__construct($value);
    }

    /** @psalm-pure */
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
}
