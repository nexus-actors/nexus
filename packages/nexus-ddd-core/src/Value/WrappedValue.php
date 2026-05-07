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
     * Endomap — re-construct via static, re-running the subclass constructor
     * (which re-runs validation if the subclass enforces invariants on its
     * inner value). The result is the SAME wrapper type with a transformed
     * inner value.
     *
     * NOTE: this is *not* a free functor. The transformation is `T -> T`,
     * not `T -> U` for arbitrary `U`, because PHP's `new static(...)` can
     * only accept the subclass's own inner type. To produce a different
     * value-object type from this one, use `flatMap` (which constructs the
     * target wrapper explicitly inside the callback).
     *
     * @param callable(T): T $fn
     * @return static
     * @psalm-suppress ImpureFunctionCall,UnsafeInstantiation
     */
    #[\NoDiscard('map() returns the transformed value object — ignoring it loses the transformation')]
    public function map(callable $fn): static
    {
        return new static($fn($this->value));
    }

    /**
     * Cross-type bind — the callback is responsible for constructing the
     * target value-object explicitly (e.g. `fn(string $email) => new
     * EmailAddress($email)`), so any validation in the target's constructor
     * runs as expected.
     *
     * @template U of WrappedValue
     * @param callable(T): U $fn
     * @return U
     * @psalm-suppress ImpureFunctionCall
     */
    #[\NoDiscard('flatMap() returns the produced value object — ignoring it loses the transformation')]
    public function flatMap(callable $fn): WrappedValue
    {
        return $fn($this->value);
    }

    public function equals(object $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }
}
