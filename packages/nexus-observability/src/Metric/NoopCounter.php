<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

use Override;

/** @psalm-api */
final class NoopCounter implements Counter
{
    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function add(int|float $value, array $attributes = []): void
    {
        // no-op — metrics are disabled when observability is not configured.
    }
}
