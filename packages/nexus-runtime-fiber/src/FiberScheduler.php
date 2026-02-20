<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use Closure;
use DateTimeImmutable;
use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Duration;

/** @psalm-api */
final class FiberScheduler
{
    /** @var list<TimerEntry> */
    private array $timers = [];

    /**
     * @param Closure(): void $callback
     */
    public function scheduleOnce(Duration $delay, Closure $callback, DateTimeImmutable $now): Cancellable
    {
        $cancellable = new FiberCancellable();
        $fireAt = $this->addDuration($now, $delay);

        $timer = new TimerEntry(
            callback: $callback,
            fireAt: $fireAt,
            repeating: false,
            interval: null,
            cancellable: $cancellable,
        );

        $this->insertSorted($timer);

        return $cancellable;
    }

    /**
     * @param Closure(): void $callback
     */
    public function scheduleRepeatedly(
        Duration $initialDelay,
        Duration $interval,
        Closure $callback,
        DateTimeImmutable $now,
    ): Cancellable {
        $cancellable = new FiberCancellable();
        $fireAt = $this->addDuration($now, $initialDelay);

        $timer = new TimerEntry(
            callback: $callback,
            fireAt: $fireAt,
            repeating: true,
            interval: $interval,
            cancellable: $cancellable,
        );

        $this->insertSorted($timer);

        return $cancellable;
    }

    public function advanceTimers(DateTimeImmutable $now): void
    {
        $remaining = [];

        foreach ($this->timers as $timer) {
            if ($timer->cancellable->isCancelled()) {
                continue;
            }

            if ($timer->fireAt <= $now) {
                ($timer->callback)();

                /** @psalm-suppress RedundantCondition -- defensive guard for timer invariants */
                if ($timer->repeating && $timer->interval !== null && !$timer->cancellable->isCancelled()) {
                    $remaining[] = new TimerEntry(
                        callback: $timer->callback,
                        fireAt: $this->addDuration($timer->fireAt, $timer->interval),
                        repeating: true,
                        interval: $timer->interval,
                        cancellable: $timer->cancellable,
                    );
                }
            } else {
                $remaining[] = $timer;
            }
        }

        $this->timers = $remaining;
        usort($this->timers, static fn (TimerEntry $a, TimerEntry $b): int => $a->fireAt <=> $b->fireAt);
    }

    public function hasPendingTimers(): bool
    {
        // Filter out cancelled timers
        foreach ($this->timers as $timer) {
            if (!$timer->cancellable->isCancelled()) {
                return true;
            }
        }

        return false;
    }

    private function addDuration(DateTimeImmutable $time, Duration $duration): DateTimeImmutable
    {
        $micros = $duration->toMicros();
        $seconds = intdiv($micros, 1_000_000);
        $remainingMicros = $micros % 1_000_000;

        $result = $time->modify("+{$seconds} seconds");

        if ($result === false) {
            return $time;
        }

        if ($remainingMicros > 0) {
            $modified = $result->modify("+{$remainingMicros} microseconds");

            return $modified !== false
                ? $modified
                : $result;
        }

        return $result;
    }

    /**
     * Insert a timer in sorted position (O(n) scan, avoids O(n log n) full sort).
     */
    private function insertSorted(TimerEntry $timer): void
    {
        $count = count($this->timers);

        for ($i = $count - 1; $i >= 0; $i--) {
            if ($this->timers[$i]->fireAt <= $timer->fireAt) {
                array_splice($this->timers, $i + 1, 0, [$timer]);

                return;
            }
        }

        array_splice($this->timers, 0, 0, [$timer]);
    }
}
