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
 *
 * `getValue()` is protected — value objects encapsulate their inner value;
 * subclasses expose typed domain accessors (e.g. `asString()`, `domain()`)
 * built on top. Infrastructure layers that need raw extraction use a separate
 * `ValueExtractor` (introduced in nexus-ddd-messaging / nexus-ddd-aggregate).
 */
abstract readonly class WrappedValue
{
    /** @param T $value */
    protected function __construct(private mixed $value) {}

    /** @return T */
    protected function getValue(): mixed
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

    public function equals(object $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }
}
