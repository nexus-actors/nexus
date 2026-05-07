<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Policy;

/**
 * @psalm-api
 *
 * @template TIn
 * @template TMid
 * @template TOut
 *
 * @extends AbstractPolicy<TIn, TOut>
 *
 * Composition of two Policies — `first` runs, its output feeds `next`.
 * Created by `AbstractPolicy::then()`; not constructed directly by domain
 * code (the public composition surface is `->then(...)`).
 */
final class ComposedPolicy extends AbstractPolicy
{
    /**
     * @param AbstractPolicy<TIn, TMid> $first
     * @param AbstractPolicy<TMid, TOut> $next
     */
    public function __construct(
        private readonly AbstractPolicy $first,
        private readonly AbstractPolicy $next,
    ) {}

    /**
     * @param TIn $input
     * @return TOut
     */
    #[\Override]
    public function apply(mixed $input): mixed
    {
        /** @var TMid $intermediate */
        $intermediate = $this->first->apply($input);

        return $this->next->apply($intermediate);
    }
}
