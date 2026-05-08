<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 *
 * @template T
 *
 * Predicate over a candidate of type T, with combinators for composition.
 *
 * Note: PHP cannot enforce the template parameter at the interface level —
 * `isSatisfiedBy(mixed $candidate)` is the runtime signature; the `T` template
 * is a Psalm-only annotation. Concrete impls restate types via docblock.
 */
interface Specification
{
    /** @param T $candidate */
    public function isSatisfiedBy(mixed $candidate): bool;

    /** @param Specification<T> $other @return Specification<T> */
    public function and(self $other): self;

    /** @param Specification<T> $other @return Specification<T> */
    public function or(self $other): self;

    /** @return Specification<T> */
    public function not(): self;
}
