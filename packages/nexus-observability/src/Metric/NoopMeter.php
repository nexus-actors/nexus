<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

use Override;

/** @psalm-api */
final readonly class NoopMeter implements Meter
{
    public function __construct(
        private Counter $counter = new NoopCounter(),
        private UpDownCounter $upDownCounter = new NoopUpDownCounter(),
        private Histogram $histogram = new NoopHistogram(),
        private ObservableGauge $observableGauge = new NoopObservableGauge(),
    ) {}

    #[Override]
    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return $this->counter;
    }

    #[Override]
    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return $this->upDownCounter;
    }

    #[Override]
    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return $this->histogram;
    }

    #[Override]
    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge {
        return $this->observableGauge;
    }
}
