<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber\Tests\Unit;

use DateTimeImmutable;
use Monadial\Nexus\Runtime\Duration;
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
    public function timer_scheduled_inside_callback_fires_on_next_advance(): void
    {
        $scheduler = new FiberScheduler();
        $innerFired = false;

        $now = new DateTimeImmutable('2026-01-01 00:00:00');

        // Outer timer fires at T+1; its callback schedules an inner timer (fireAt = T+0 + 1s = T+1).
        $scheduler->scheduleOnce(
            Duration::seconds(1),
            static function () use ($scheduler, &$innerFired, $now): void {
                $scheduler->scheduleOnce(
                    Duration::seconds(1),
                    static function () use (&$innerFired): void {
                        $innerFired = true;
                    },
                    $now,
                );
            },
            $now,
        );

        // Advance to T+1: outer fires and schedules the inner timer — inner must NOT fire yet.
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:01'));
        self::assertFalse($innerFired);

        // Advance to T+2: inner timer (fireAt = T+1) is now due and must fire.
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:02'));
        self::assertTrue($innerFired);
    }

    #[Test]
    public function repeating_timer_scheduled_inside_callback_keeps_firing(): void
    {
        $scheduler = new FiberScheduler();
        $repeatCount = 0;

        $now = new DateTimeImmutable('2026-01-01 00:00:00');

        // Outer one-shot fires at T+1; registers a repeating timer (initialDelay=1s, interval=1s, fireAt=T+1).
        $scheduler->scheduleOnce(
            Duration::seconds(1),
            static function () use ($scheduler, &$repeatCount, $now): void {
                $scheduler->scheduleRepeatedly(
                    Duration::seconds(1),
                    Duration::seconds(1),
                    static function () use (&$repeatCount): void {
                        $repeatCount++;
                    },
                    $now,
                );
            },
            $now,
        );

        // T+1: outer fires, inner repeating timer is registered (fireAt=T+1) but NOT fired this pass.
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:01'));
        self::assertSame(0, $repeatCount);

        // T+2: repeating fires first time (fireAt=T+1 <= T+2).
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:02'));
        self::assertSame(1, $repeatCount);

        // T+3: repeating fires second time (re-scheduled at T+2 <= T+3).
        $scheduler->advanceTimers(new DateTimeImmutable('2026-01-01 00:00:03'));
        self::assertSame(2, $repeatCount);
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
