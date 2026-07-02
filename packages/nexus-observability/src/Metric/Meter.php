<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
interface Meter
{
    public function counter(string $name, string $unit = '', string $description = ''): Counter;

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter;

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram;

    /**
     * @param callable(): (int|float) $callback
     */
    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge;
}
