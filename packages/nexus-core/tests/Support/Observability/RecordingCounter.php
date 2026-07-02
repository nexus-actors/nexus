<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Metric\Counter;

final readonly class RecordingCounter implements Counter
{
    public function __construct(private string $name, private RecordingMeter $meter) {}

    /**
     * @param array<string, scalar> $attributes
     */
    public function add(int|float $value, array $attributes = []): void
    {
        $this->meter->record(new RecordedMetric('counter', $this->name, $value, $attributes));
    }
}
