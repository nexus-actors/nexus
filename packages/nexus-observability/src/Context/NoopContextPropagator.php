<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

/**
 * @psalm-api
 *
 * Zero-overhead propagator used when observability is disabled: injects nothing
 * and always extracts the (possibly supplied) context unchanged.
 */
final class NoopContextPropagator implements ContextPropagator
{
    public function inject(Context $context, array &$carrier): void {}

    public function extract(array $carrier, ?Context $context = null): Context
    {
        return $context ?? Context::root();
    }
}
