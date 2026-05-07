<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Policy;

/**
 * @psalm-api
 *
 * @template TIn
 * @template TOut
 *
 * Named domain rule that COMPUTES a decision from a context — pricing
 * rules, eligibility checks, scoring functions, discount calculations.
 * Distinct from a Specification (which returns bool) and from a plain
 * `callable` (which is anonymous and uncomposable).
 *
 * Two affordances make this a Policy and not a callable:
 *
 * 1. **Identity** — every Policy is a class with a meaningful name. The
 *    type system carries the domain concept (`VolumeDiscountPolicy`,
 *    `ChurnRiskPolicy`). A `callable` cannot. The class name *is* part
 *    of the ubiquitous language.
 *
 * 2. **Composition** — `then()` chains a follow-on Policy whose input is
 *    this Policy's output. The composed result is itself a Policy, so
 *    chains are first-class domain objects that can be passed, swapped,
 *    and tested as a whole.
 *
 * Concrete subclasses MUST declare TIn / TOut for downstream type
 * inference (PHP cannot enforce templates at the interface level, so
 * concrete impls restate types via docblock).
 */
abstract class AbstractPolicy
{
    /**
     * @param TIn $input
     * @return TOut
     */
    abstract public function apply(mixed $input): mixed;

    /**
     * Compose: this policy first, then $next on its output. The composed
     * Policy is itself a Policy — chains remain a domain concept.
     *
     * @template TNext
     * @param AbstractPolicy<TOut, TNext> $next
     * @return AbstractPolicy<TIn, TNext>
     */
    #[\NoDiscard('then() returns the composed policy — discarding it loses the composition')]
    final public function then(AbstractPolicy $next): AbstractPolicy
    {
        /** @var AbstractPolicy<TIn, TNext> */
        return new ComposedPolicy($this, $next);
    }
}
