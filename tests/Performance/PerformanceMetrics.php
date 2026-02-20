<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance;

/**
 * Value object for benchmark results.
 */
final readonly class PerformanceMetrics
{
    public function __construct(
        public string $name,
        public float $elapsedMs,
        public int $operations,
        public float $opsPerSecond,
        public int $peakMemoryBytes,
        public int $memoryDeltaBytes,
    ) {}

    public function report(): string
    {
        return sprintf(
            "[%s] %s ops in %.1fms (%.0f ops/sec) | peak=%s delta=%s",
            $this->name,
            number_format($this->operations),
            $this->elapsedMs,
            $this->opsPerSecond,
            self::formatBytes($this->peakMemoryBytes),
            self::formatBytes($this->memoryDeltaBytes),
        );
    }

    /**
     * @return array{elapsedMs: float, memoryDeltaBytes: int, name: string, operations: int, opsPerSecond: float, peakMemoryBytes: int}
     */
    public function toArray(): array
    {
        return [
            'elapsedMs' => $this->elapsedMs,
            'memoryDeltaBytes' => $this->memoryDeltaBytes,
            'name' => $this->name,
            'operations' => $this->operations,
            'opsPerSecond' => $this->opsPerSecond,
            'peakMemoryBytes' => $this->peakMemoryBytes,
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        $abs = abs($bytes);

        if ($abs >= 1_048_576) {
            return sprintf('%.1fMB', $bytes / 1_048_576);
        }

        if ($abs >= 1_024) {
            return sprintf('%.1fKB', $bytes / 1_024);
        }

        return sprintf('%dB', $bytes);
    }
}
