<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopObservableGauge;
use Monadial\Nexus\Observability\Metric\ObservableGauge;
use Monadial\Nexus\Observability\Metric\UpDownCounter;

final class RecordingMeter implements Meter
{
    /** @var list<RecordedMetric> */
    public array $metrics = [];

    public function record(RecordedMetric $metric): void
    {
        $this->metrics[] = $metric;
    }

    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return new RecordingCounter($name, $this);
    }

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return new RecordingUpDownCounter($name, $this);
    }

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return new RecordingHistogram($name, $this);
    }

    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge {
        return new NoopObservableGauge();
    }
}
