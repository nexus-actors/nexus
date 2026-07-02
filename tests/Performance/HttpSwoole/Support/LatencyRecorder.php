<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\HttpSwoole\Support;

use function count;
use function floor;
use function max;
use function min;
use function sort;

/**
 * Records latency samples in nanoseconds. Emits sorted percentile arrays.
 *
 * @psalm-api
 */
final class LatencyRecorder
{
    /** @var list<int> */
    private array $samples = [];

    public function record(int $nanos): void
    {
        $this->samples[] = $nanos;
    }

    public function count(): int
    {
        return count($this->samples);
    }

    public function p50(): int
    {
        return $this->percentile(0.50);
    }

    public function p95(): int
    {
        return $this->percentile(0.95);
    }

    public function p99(): int
    {
        return $this->percentile(0.99);
    }

    public function max(): int
    {
        if ($this->samples === []) {
            return 0;
        }

        return max($this->samples);
    }

    public function min(): int
    {
        if ($this->samples === []) {
            return 0;
        }

        return min($this->samples);
    }

    /**
     * @return array{count: int, max: int, min: int, p50: int, p95: int, p99: int}
     */
    public function summary(): array
    {
        return [
            'count' => $this->count(),
            'max' => $this->max(),
            'min' => $this->min(),
            'p50' => $this->p50(),
            'p95' => $this->p95(),
            'p99' => $this->p99(),
        ];
    }

    /**
     * @psalm-suppress InvalidOperand count() * $p mixes int and float for percentile index.
     */
    private function percentile(float $p): int
    {
        if ($this->samples === []) {
            return 0;
        }

        $sorted = $this->samples;
        sort($sorted);
        $index = (int) floor(count($sorted) * $p);
        $index = min($index, count($sorted) - 1);

        return $sorted[$index];
    }
}
