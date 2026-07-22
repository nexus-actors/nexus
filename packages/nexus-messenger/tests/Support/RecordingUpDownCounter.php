<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Monadial\Nexus\Observability\Metric\UpDownCounter;
use Override;

use function max;

final class RecordingUpDownCounter implements UpDownCounter
{
    public int|float $value = 0;

    /** Highest value the gauge reached — captures the peak even after it drains back down. */
    public int|float $peak = 0;

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function add(int|float $value, array $attributes = []): void
    {
        $this->value += $value;
        $this->peak = max($this->peak, $this->value);
    }
}
