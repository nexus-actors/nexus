<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopHistogram;
use Monadial\Nexus\Observability\Metric\NoopObservableGauge;
use Monadial\Nexus\Observability\Metric\ObservableGauge;
use Monadial\Nexus\Observability\Metric\UpDownCounter;
use Override;

final class RecordingMeter implements Meter
{
    /** @var array<string, RecordingCounter> */
    public array $counters = [];

    /** @var array<string, RecordingUpDownCounter> */
    public array $upDownCounters = [];

    #[Override]
    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return $this->counters[$name] ??= new RecordingCounter();
    }

    #[Override]
    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return $this->upDownCounters[$name] ??= new RecordingUpDownCounter();
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

    /** Current value of an up-down counter gauge (0 if never touched). */
    public function gaugeValue(string $name): int|float
    {
        return isset($this->upDownCounters[$name])
            ? $this->upDownCounters[$name]->value
            : 0;
    }

    /** Highest value an up-down counter gauge ever reached (0 if never touched). */
    public function gaugePeak(string $name): int|float
    {
        return isset($this->upDownCounters[$name])
            ? $this->upDownCounters[$name]->peak
            : 0;
    }
}
