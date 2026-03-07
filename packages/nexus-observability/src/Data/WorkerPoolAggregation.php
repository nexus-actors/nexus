<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Data;

/**
 * @psalm-api
 *
 * Aggregated telemetry from all worker threads in a pool.
 */
final readonly class WorkerPoolAggregation
{
    /**
     * @param WorkerTelemetryEntry[] $entries
     */
    public function __construct(
        public array $entries,
        public int $totalCoroutines,
        public int $totalDeadLetters,
        public int $totalMemoryBytes,
        public int $totalTimers,
    ) {}

    public static function fromEntries(WorkerTelemetryEntry ...$entries): self
    {
        $totalCoroutines  = 0;
        $totalDeadLetters = 0;
        $totalMemoryBytes = 0;
        $totalTimers      = 0;

        foreach ($entries as $entry) {
            $totalCoroutines  += $entry->runtime->coroutineNum;
            $totalDeadLetters += $entry->system->deadLettersCount;
            $totalMemoryBytes += $entry->runtime->memoryBytes;
            $totalTimers      += $entry->runtime->activeTimers;
        }

        return new self(
            entries: array_values($entries),
            totalCoroutines: $totalCoroutines,
            totalDeadLetters: $totalDeadLetters,
            totalMemoryBytes: $totalMemoryBytes,
            totalTimers: $totalTimers,
        );
    }
}
