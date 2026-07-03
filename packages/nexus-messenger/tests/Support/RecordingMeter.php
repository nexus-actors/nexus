<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopHistogram;
use Monadial\Nexus\Observability\Metric\NoopObservableGauge;
use Monadial\Nexus\Observability\Metric\NoopUpDownCounter;
use Monadial\Nexus\Observability\Metric\ObservableGauge;
use Monadial\Nexus\Observability\Metric\UpDownCounter;
use Override;

final class RecordingMeter implements Meter
{
    /** @var array<string, RecordingCounter> */
    public array $counters = [];

    #[Override]
    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return $this->counters[$name] ??= new RecordingCounter();
    }

    #[Override]
    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return new NoopUpDownCounter();
    }

    #[Override]
    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return new NoopHistogram();
    }

    /**
     * @param callable(): (int|float) $callback
     */
    #[Override]
    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge {
        return new NoopObservableGauge();
    }

    public function sum(string $name): int|float
    {
        return isset($this->counters[$name])
            ? $this->counters[$name]->total
            : 0;
    }
}
