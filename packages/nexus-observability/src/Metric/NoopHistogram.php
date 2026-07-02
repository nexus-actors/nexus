<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
final class NoopHistogram implements Histogram
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function record(int|float $value, array $attributes = []): void {}
}
