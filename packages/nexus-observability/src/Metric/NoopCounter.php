<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
final class NoopCounter implements Counter
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function add(int|float $value, array $attributes = []): void {}
}
