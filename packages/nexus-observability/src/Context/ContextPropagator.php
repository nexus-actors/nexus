<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

/**
 * @psalm-api
 *
 * Injects/extracts a {@see Context} into/from a string carrier (message
 * metadata or HTTP headers). `extract` takes an optional accumulator context so
 * multiple propagators can compose (see {@see CompositePropagator}).
 */
interface ContextPropagator
{
    /**
     * @param array<string, string> $carrier
     */
    public function inject(Context $context, array &$carrier): void;

    /**
     * @param array<string, string> $carrier
     */
    public function extract(array $carrier, ?Context $context = null): Context;
}
