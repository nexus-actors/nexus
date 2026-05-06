<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Default canonical format: components joined by `:`, with values URL-encoded.
 * Subclasses pass their components to the constructor; fromString reconstructs.
 *
 * Override `canonicalize`/`parseComponents` for custom formats; the round-trip
 * MUST be deterministic.
 */
abstract class AbstractCompositeIdentifier implements CompositeIdentifier
{
    /** @param array<string, scalar> $components */
    protected function __construct(private readonly array $components) {}

    /** @return array<string, scalar> */
    final public function components(): array
    {
        return $this->components;
    }

    public function value(): string
    {
        return implode(
            ':',
            array_map(
                static fn(mixed $v): string => rawurlencode((string) $v),
                array_values($this->components),
            ),
        );
    }

    public function equals(Identifier $other): bool
    {
        if (! $other instanceof static) {
            return false;
        }

        return $other->components === $this->components;
    }

    /**
     * Default reconstruction: subclasses MUST override if their constructor signature
     * cannot accept positional values from the canonical string parsed in declaration order.
     */
    public static function fromString(string $value): static
    {
        $parts = array_map(
            static fn(string $p): string => rawurldecode($p),
            explode(':', $value),
        );
        try {
            // Subclass constructors typically accept positional args matching component declaration order
            // @psalm-suppress UnsafeInstantiation
            return new static(...$parts);
        } catch (\Throwable $e) {
            throw InvalidIdentifierException::malformed(static::class, $value, $e->getMessage());
        }
    }
}
