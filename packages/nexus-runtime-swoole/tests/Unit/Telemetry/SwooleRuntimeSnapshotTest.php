<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit\Telemetry;

use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwooleRuntimeSnapshot::class)]
final class SwooleRuntimeSnapshotTest extends TestCase
{
    #[Test]
    public function round_trips_through_array(): void
    {
        $snap     = new SwooleRuntimeSnapshot(12, 20, 4, 8_388_608, 12_582_912);
        $restored = SwooleRuntimeSnapshot::fromArray($snap->toArray());

        self::assertSame(12, $restored->coroutineNum);
        self::assertSame(20, $restored->coroutinePeakNum);
        self::assertSame(4, $restored->activeTimers);
        self::assertSame(8_388_608, $restored->memoryBytes);
        self::assertSame(12_582_912, $restored->memoryPeakBytes);
    }
}
