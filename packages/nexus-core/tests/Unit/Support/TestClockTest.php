<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Support;

use DateTimeImmutable;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TestClockTest extends TestCase
{
    #[Test]
    public function nowReturnsFrozenDefaultTime(): void
    {
        $clock = new TestClock();

        $expected = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertEquals($expected, $clock->now());
    }

    #[Test]
    public function nowReturnsCustomStartTime(): void
    {
        $start = new DateTimeImmutable('2025-06-15T12:30:00+00:00');
        $clock = new TestClock($start);

        self::assertEquals($start, $clock->now());
    }

    #[Test]
    public function nowReturnsSameTimeTwice(): void
    {
        $clock = new TestClock();

        $first = $clock->now();
        $second = $clock->now();

        self::assertEquals($first, $second);
    }

    #[Test]
    public function advanceMovesTimeForwardBySeconds(): void
    {
        $clock = new TestClock();
        $before = $clock->now();

        $clock->advance(Duration::seconds(5));

        $after = $clock->now();
        $diff = $after->getTimestamp() - $before->getTimestamp();

        self::assertSame(5, $diff);
    }

    #[Test]
    public function advanceMovesTimeForwardByMilliseconds(): void
    {
        $clock = new TestClock();
        $before = $clock->now();

        $clock->advance(Duration::millis(500));

        $after = $clock->now();
        // 500ms = 500,000 microseconds
        $diffMicros = (int) (($after->format('U.u') - $before->format('U.u')) * 1_000_000);

        self::assertSame(500_000, $diffMicros);
    }

    #[Test]
    public function advanceAccumulatesMultipleCalls(): void
    {
        $clock = new TestClock();
        $before = $clock->now();

        $clock->advance(Duration::seconds(3));
        $clock->advance(Duration::seconds(7));

        $after = $clock->now();
        $diff = $after->getTimestamp() - $before->getTimestamp();

        self::assertSame(10, $diff);
    }

    #[Test]
    public function setReplacesCurrentTime(): void
    {
        $clock = new TestClock();
        $newTime = new DateTimeImmutable('2030-12-25T18:00:00+00:00');

        $clock->set($newTime);

        self::assertEquals($newTime, $clock->now());
    }

    #[Test]
    public function setOverridesPreviousAdvances(): void
    {
        $clock = new TestClock();
        $clock->advance(Duration::seconds(100));

        $newTime = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $clock->set($newTime);

        self::assertEquals($newTime, $clock->now());
    }
}
