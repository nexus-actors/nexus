<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
final class NoopMeter implements Meter
{
    public function __construct(
        private readonly Counter $counter = new NoopCounter(),
        private readonly UpDownCounter $upDownCounter = new NoopUpDownCounter(),
        private readonly Histogram $histogram = new NoopHistogram(),
        private readonly ObservableGauge $observableGauge = new NoopObservableGauge(),
    ) {}

    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return $this->counter;
    }

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return $this->upDownCounter;
    }

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return $this->histogram;
    }

    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge {
        return $this->observableGauge;
    }
}
