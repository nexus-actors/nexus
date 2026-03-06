<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Telemetry;

/**
 * @psalm-api
 *
 * Immutable snapshot of SwooleRuntime's observable state at a point in time.
 */
final readonly class SwooleRuntimeSnapshot
{
    public function __construct(
        public int $coroutineNum,
        public int $coroutinePeakNum,
        public int $activeTimers,
        public int $memoryBytes,
        public int $memoryPeakBytes,
    ) {}

    /**
     * @param array<string, int> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            coroutineNum: $data['coroutine_num'] ?? 0,
            coroutinePeakNum: $data['coroutine_peak_num'] ?? 0,
            activeTimers: $data['active_timers'] ?? 0,
            memoryBytes: $data['memory_bytes'] ?? 0,
            memoryPeakBytes: $data['memory_peak_bytes'] ?? 0,
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'active_timers' => $this->activeTimers,
            'coroutine_num' => $this->coroutineNum,
            'coroutine_peak_num' => $this->coroutinePeakNum,
            'memory_bytes' => $this->memoryBytes,
            'memory_peak_bytes' => $this->memoryPeakBytes,
        ];
    }
}
