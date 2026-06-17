<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmPoolStats::class)]
final class EmPoolStatsTest extends TestCase
{
    #[Test]
    public function empty(): void
    {
        $s = EmPoolStats::empty();
        self::assertSame(0, $s->idle);
        self::assertSame(0, $s->inUse);
        self::assertSame(0, $s->total);
        self::assertSame(0, $s->totalBorrows);
        self::assertSame(0, $s->totalEvictions);
    }

    #[Test]
    public function explicitConstructionRetainsValues(): void
    {
        $s = new EmPoolStats(idle: 3, inUse: 5, total: 8, totalBorrows: 100, totalEvictions: 7);

        self::assertSame(3, $s->idle);
        self::assertSame(8, $s->total);
        self::assertSame(7, $s->totalEvictions);
    }
}
