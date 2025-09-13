<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber\Tests\Unit;

use DateTimeImmutable;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberScheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FiberScheduler::class)]
final class FiberSchedulerTest extends TestCase
{
    #[Test]
    public function schedule_once_fires_at_correct_time(): void
    {
        $scheduler = new FiberScheduler();
        $fired = false;

        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $scheduler->scheduleOnce(Duration::seconds(5), static function () use (&$fired): void {
            $fired = true;
        }, $now);

        // Not yet due at +4 seconds
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:04'));
        self::assertFalse($fired);

        // Due at +5 seconds
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:05'));
        self::assertTrue($fired);
    }

    #[Test]
    public function schedule_once_does_not_fire_again(): void
    {
        $scheduler = new FiberScheduler();
        $count = 0;

        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $scheduler->scheduleOnce(Duration::seconds(1), static function () use (&$count): void {
            $count++;
        }, $now);

        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:01'));
        self::assertSame(1, $count);

        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:02'));
        self::assertSame(1, $count);
    }

    #[Test]
    public function schedule_repeatedly_fires_repeatedly(): void
    {
        $scheduler = new FiberScheduler();
        $count = 0;

        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $scheduler->scheduleRepeatedly(
            Duration::seconds(2),
            Duration::seconds(3),
            static function () use (&$count): void {
                $count++;
            },
            $now,
        );

        // Not due at +1s
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:01'));
        self::assertSame(0, $count);

        // Initial delay fires at +2s
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:02'));
        self::assertSame(1, $count);

        // Not due at +4s (next fire at +5s)
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:04'));
        self::assertSame(1, $count);

        // Due at +5s (2s initial + 3s interval)
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:05'));
        self::assertSame(2, $count);

        // Due at +8s (2s initial + 3s + 3s)
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:08'));
        self::assertSame(3, $count);
    }

    #[Test]
    public function cancellation_prevents_firing(): void
    {
        $scheduler = new FiberScheduler();
        $fired = false;

        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $cancellable = $scheduler->scheduleOnce(Duration::seconds(1), static function () use (&$fired): void {
            $fired = true;
        }, $now);

        $cancellable->cancel();
        self::assertTrue($cancellable->isCancelled());

        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:01'));
        self::assertFalse($fired);
    }

    #[Test]
    public function cancellation_prevents_repeated_firing(): void
    {
        $scheduler = new FiberScheduler();
        $count = 0;

        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $cancellable = $scheduler->scheduleRepeatedly(
            Duration::seconds(1),
            Duration::seconds(1),
            static function () use (&$count): void {
                $count++;
            },
            $now,
        );

        // First fire
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:01'));
        self::assertSame(1, $count);

        // Cancel before next fire
        $cancellable->cancel();

        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:02'));
        self::assertSame(1, $count);
    }

    #[Test]
    public function timer_ordering_earlier_timers_fire_first(): void
    {
        $scheduler = new FiberScheduler();
        /** @var list<string> $order */
        $order = [];

        $now = new DateTimeImmutable('2026-01-01 00:00:00');

        // Schedule later timer first
        $scheduler->scheduleOnce(Duration::seconds(3), static function () use (&$order): void {
            $order[] = 'third';
        }, $now);

        $scheduler->scheduleOnce(Duration::seconds(1), static function () use (&$order): void {
            $order[] = 'first';
        }, $now);

        $scheduler->scheduleOnce(Duration::seconds(2), static function () use (&$order): void {
            $order[] = 'second';
        }, $now);

        // Advance past all timers
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:05'));

        self::assertSame(['first', 'second', 'third'], $order);
    }

    #[Test]
    public function has_pending_timers_reflects_state(): void
    {
        $scheduler = new FiberScheduler();

        self::assertFalse($scheduler->hasPendingTimers());

        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $scheduler->scheduleOnce(Duration::seconds(1), static function (): void {}, $now);

        self::assertTrue($scheduler->hasPendingTimers());

        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:01'));

        self::assertFalse($scheduler->hasPendingTimers());
    }
}
