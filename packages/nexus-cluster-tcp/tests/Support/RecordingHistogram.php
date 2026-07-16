<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Observability\Metric\Histogram;
use Override;

final class RecordingHistogram implements Histogram
{
    public int|float $total = 0;

    /** @var list<array{attributes: array<string, scalar>, value: int|float}> */
    public array $records = [];

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function record(int|float $value, array $attributes = []): void
    {
        $this->total += $value;
        $this->records[] = ['attributes' => $attributes, 'value' => $value];
    }
}
