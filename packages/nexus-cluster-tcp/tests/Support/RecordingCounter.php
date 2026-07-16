<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Observability\Metric\Counter;
use Override;

final class RecordingCounter implements Counter
{
    public int|float $total = 0;

    /** @var list<array{attributes: array<string, scalar>, value: int|float}> */
    public array $adds = [];

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function add(int|float $value, array $attributes = []): void
    {
        $this->total += $value;
        $this->adds[] = ['attributes' => $attributes, 'value' => $value];
    }
}
