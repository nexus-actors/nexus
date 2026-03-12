<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value;

use Closure;
use Override;
use Stringable;

/**
 * @psalm-api
 *
 * @template T
 */
abstract readonly class Value implements Stringable
{
    abstract public function equals(mixed $other): bool;

    /** @psalm-param T $value */
    protected function __construct(protected mixed $value) {}

    /**
     * Apply a transformation to the wrapped value, returning the raw result.
     *
     * @template R
     *
     * @psalm-param Closure(T): R $fn
     *
     * @psalm-return R
     */
    public function map(Closure $fn): mixed
    {
        return $fn($this->value);
    }

    /**
     * Apply a transformation that returns a new Value of the same type.
     *
     * @psalm-param Closure(T): static $fn
     */
    public function flatMap(Closure $fn): static
    {
        return $fn($this->value);
    }

    #[Override]
    abstract public function __toString(): string;
}
