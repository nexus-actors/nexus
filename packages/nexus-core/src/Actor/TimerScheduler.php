<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Runtime\Duration;

/**
 * @psalm-api
 *
 * Keyed timer management for actors.
 *
 * Starting a timer with a key that already exists auto-cancels the previous timer.
 * All timers are automatically cancelled when the actor stops.
 */
interface TimerScheduler
{
    /**
     * Start a single-fire timer. If a timer with this key already exists, it is cancelled first.
     */
    public function startSingleTimer(string $key, object $message, Duration $delay): void;

    /**
     * Start a repeating timer at a fixed rate (drift-compensating).
     * If a timer with this key already exists, it is cancelled first.
     */
    public function startTimerAtFixedRate(
        string $key,
        object $message,
        Duration $interval,
        ?Duration $initialDelay = null,
    ): void;

    /**
     * Start a repeating timer with a fixed delay between completions.
     * If a timer with this key already exists, it is cancelled first.
     */
    public function startTimerWithFixedDelay(
        string $key,
        object $message,
        Duration $delay,
        ?Duration $initialDelay = null,
    ): void;

    public function cancel(string $key): void;

    public function cancelAll(): void;

    public function isTimerActive(string $key): bool;
}
