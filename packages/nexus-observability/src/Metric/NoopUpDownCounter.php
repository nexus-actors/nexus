<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
final class NoopUpDownCounter implements UpDownCounter
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function add(int|float $value, array $attributes = []): void {}
}
