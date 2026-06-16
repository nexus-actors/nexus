<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoolStats::class)]
final class PoolStatsTest extends TestCase
{
    #[Test]
    public function emptyStats(): void
    {
        $stats = PoolStats::empty();

        self::assertSame(0, $stats->idle);
        self::assertSame(0, $stats->inUse);
        self::assertSame(0, $stats->total);
        self::assertSame(0, $stats->waitingCoroutines);
        self::assertSame(0, $stats->totalBorrows);
        self::assertSame(0, $stats->totalWaits);
        self::assertSame(0, $stats->totalTimeouts);
    }

    #[Test]
    public function explicitConstructionRetainsAllValues(): void
    {
        $stats = new PoolStats(
            idle: 3,
            inUse: 5,
            total: 8,
            waitingCoroutines: 2,
            totalBorrows: 100,
            totalWaits: 10,
            totalTimeouts: 1,
        );

        self::assertSame(8, $stats->total);
        self::assertSame(2, $stats->waitingCoroutines);
        self::assertSame(100, $stats->totalBorrows);
    }
}
