<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance;

/**
 * Utility for measuring performance metrics.
 */
final class Benchmark
{
    /**
     * @param callable(): void $fn
     *
     * @psalm-suppress InvalidOperand
     */
    public static function measure(string $name, int $operations, callable $fn): PerformanceMetrics
    {
        gc_collect_cycles();
        $memBefore = memory_get_usage(true);

        $start = hrtime(true);
        $fn();
        $elapsed = (hrtime(true) - $start) / 1_000_000; // ms

        $peakMem = memory_get_peak_usage(true);
        $memAfter = memory_get_usage(true);

        $metrics = new PerformanceMetrics(
            name: $name,
            elapsedMs: $elapsed,
            operations: $operations,
            opsPerSecond: $elapsed > 0 ? $operations / $elapsed * 1000 : 0,
            peakMemoryBytes: $peakMem,
            memoryDeltaBytes: $memAfter - $memBefore,
        );

        $jsonPath = getenv('BENCHMARK_JSON');

        if ($jsonPath !== false) {
            file_put_contents(
                $jsonPath,
                json_encode($metrics->toArray(), JSON_THROW_ON_ERROR) . "\n",
                FILE_APPEND,
            );
        }

        return $metrics;
    }
}
