<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use DateTimeImmutable;
use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Duration;

final class FiberScheduler
{
    /** @var list<TimerEntry> */
    private array $timers = [];

    /**
     * @param \Closure(): void $callback
     */
    public function scheduleOnce(Duration $delay, \Closure $callback, DateTimeImmutable $now): Cancellable
    {
        $cancellable = new FiberCancellable();
        $fireAt = $this->addDuration($now, $delay);

        $this->timers[] = new TimerEntry(
            callback: $callback,
            fireAt: $fireAt,
            repeating: false,
            interval: null,
            cancellable: $cancellable,
        );

        $this->sortTimers();

        return $cancellable;
    }

    /**
     * @param \Closure(): void $callback
     */
    public function scheduleRepeatedly(
        Duration $initialDelay,
        Duration $interval,
        \Closure $callback,
        DateTimeImmutable $now,
    ): Cancellable {
        $cancellable = new FiberCancellable();
        $fireAt = $this->addDuration($now, $initialDelay);

        $this->timers[] = new TimerEntry(
            callback: $callback,
            fireAt: $fireAt,
            repeating: true,
            interval: $interval,
            cancellable: $cancellable,
        );

        $this->sortTimers();

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

                if ($timer->repeating && $timer->interval !== null && !$timer->cancellable->isCancelled()) { // @phpstan-ignore booleanNot.alwaysTrue
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
        $this->sortTimers();
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

            return $modified !== false ? $modified : $result;
        }

        return $result;
    }

    private function sortTimers(): void
    {
        usort($this->timers, static fn (TimerEntry $a, TimerEntry $b): int => $a->fireAt <=> $b->fireAt);
    }
}
