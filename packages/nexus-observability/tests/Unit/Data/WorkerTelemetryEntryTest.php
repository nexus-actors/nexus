<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Data;

use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use Monadial\Nexus\Observability\Data\WorkerPoolAggregation;
use Monadial\Nexus\Observability\Data\WorkerTelemetryEntry;
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerTelemetryEntry::class)]
#[CoversClass(WorkerPoolAggregation::class)]
final class WorkerTelemetryEntryTest extends TestCase
{
    #[Test]
    public function entry_round_trips_through_json(): void
    {
        $actor   = new ActorSnapshot('/user/orders', true, 3, 1000, true, []);
        $system  = new ActorSystemSnapshot('nexus-0', 'ULID0', true, [$actor], 0);
        $runtime = new SwooleRuntimeSnapshot(
            coroutineNum: 10,
            coroutinePeakNum: 15,
            activeTimers: 2,
            memoryBytes: 1_000_000,
            memoryPeakBytes: 2_000_000,
        );
        $entry   = new WorkerTelemetryEntry(0, $system, $runtime);

        $restored = WorkerTelemetryEntry::fromJson($entry->toJson());

        self::assertSame(0, $restored->workerId);
        self::assertSame('nexus-0', $restored->system->systemName);
        self::assertSame(10, $restored->runtime->coroutineNum);
        self::assertCount(1, $restored->system->actors);
        self::assertSame('/user/orders', $restored->system->actors[0]->path);
    }

    #[Test]
    public function aggregation_sums_totals_across_entries(): void
    {
        $makeEntry = static fn(int $workerId, int $coroutines, int $timers, int $memory, int $deadLetters): WorkerTelemetryEntry =>
            new WorkerTelemetryEntry(
                $workerId,
                new ActorSystemSnapshot("nexus-{$workerId}", 'ULID', true, [], $deadLetters),
                new SwooleRuntimeSnapshot(
                    coroutineNum: $coroutines,
                    coroutinePeakNum: $coroutines,
                    activeTimers: $timers,
                    memoryBytes: $memory,
                    memoryPeakBytes: $memory,
                ),
            );

        $agg = WorkerPoolAggregation::fromEntries(
            $makeEntry(0, 10, 2, 1_000_000, 0),
            $makeEntry(1, 8, 3, 800_000, 1),
        );

        self::assertCount(2, $agg->entries);
        self::assertSame(18, $agg->totalCoroutines);
        self::assertSame(5, $agg->totalTimers);
        self::assertSame(1_800_000, $agg->totalMemoryBytes);
        self::assertSame(1, $agg->totalDeadLetters);
    }
}
