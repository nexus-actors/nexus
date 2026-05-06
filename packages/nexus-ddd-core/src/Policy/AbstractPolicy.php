<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Policy;

/**
 * @psalm-api
 *
 * @template TIn
 * @template TOut
 *
 * Domain rule that COMPUTES (pricing, discount, eligibility). Distinct from
 * Specification (predicate). Concrete subclasses MUST declare TIn / TOut for
 * downstream type inference (PHP cannot enforce templates at the interface
 * level, so concrete impls restate types via docblock).
 */
abstract class AbstractPolicy
{
    /**
     * @param TIn $input
     * @return TOut
     */
    abstract public function apply(mixed $input): mixed;
}
