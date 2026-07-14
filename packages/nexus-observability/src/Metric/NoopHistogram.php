<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

use Override;

/** @psalm-api */
final class NoopHistogram implements Histogram
{
    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function record(int|float $value, array $attributes = []): void
    {
        // no-op — metrics are disabled when observability is not configured.
    }
}
