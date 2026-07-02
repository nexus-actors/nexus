<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Metric\UpDownCounter;

final readonly class RecordingUpDownCounter implements UpDownCounter
{
    public function __construct(private string $name, private RecordingMeter $meter) {}

    /**
     * @param array<string, scalar> $attributes
     */
    public function add(int|float $value, array $attributes = []): void
    {
        $this->meter->record(new RecordedMetric('updown', $this->name, $value, $attributes));
    }
}
