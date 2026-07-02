<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Metric\Histogram;

final readonly class RecordingHistogram implements Histogram
{
    public function __construct(private string $name, private RecordingMeter $meter) {}

    /**
     * @param array<string, scalar> $attributes
     */
    public function record(int|float $value, array $attributes = []): void
    {
        $this->meter->record(new RecordedMetric('histogram', $this->name, $value, $attributes));
    }
}
