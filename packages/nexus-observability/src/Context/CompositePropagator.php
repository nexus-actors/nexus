<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

use Override;

/**
 * @psalm-api
 *
 * Runs several propagators in order. `inject` applies each; `extract` threads
 * the accumulating context through each propagator so trace context and baggage
 * end up on one {@see Context}.
 */
final readonly class CompositePropagator implements ContextPropagator
{
    /**
     * @param list<ContextPropagator> $propagators
     */
    public function __construct(private array $propagators,) {}

    #[Override]
    public function inject(Context $context, array &$carrier): void
    {
        foreach ($this->propagators as $propagator) {
            $propagator->inject($context, $carrier);
        }
    }

    #[Override]
    public function extract(array $carrier, ?Context $context = null): Context
    {
        $result = $context ?? Context::root();

        foreach ($this->propagators as $propagator) {
            $result = $propagator->extract($carrier, $result);
        }

        return $result;
    }
}
