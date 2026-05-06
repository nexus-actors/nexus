<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @template T
 *
 * Functor-style abstract for primitive-wrapping value objects.
 * Subclasses get equals(), map(), flatMap() for free.
 */
abstract class WrappedValue
{
    /** @param T $value */
    protected function __construct(private readonly mixed $value) {}

    /** @return T */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * @template U
     * @param callable(T): U $fn
     * @return static
     * @psalm-suppress ImpureFunctionCall,UnsafeInstantiation
     */
    public function map(callable $fn): static
    {
        return new static($fn($this->value));
    }

    /**
     * @template U of WrappedValue
     * @param callable(T): U $fn
     * @return U
     * @psalm-suppress ImpureFunctionCall
     */
    public function flatMap(callable $fn): WrappedValue
    {
        return $fn($this->value);
    }

    public function equals(WrappedValue $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }
}
