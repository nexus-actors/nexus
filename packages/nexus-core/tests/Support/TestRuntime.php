<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support;

use DateTimeImmutable;
use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Actor\FutureSlot;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Runtime\Runtime;

final class TestRuntime implements Runtime
{
    /** @var list<callable> */
    private array $spawned = [];

    /** @var list<array{callable, DateTimeImmutable, bool, Duration|null, TestCancellable}> */
    private array $timers = [];

    private bool $running = false;
    private int $spawnCounter = 0;

    public function __construct(private readonly TestClock $clock = new TestClock()) {}

    public function name(): string
    {
        return 'test';
    }

    public function createMailbox(MailboxConfig $config): Mailbox
    {
        return new TestMailbox($config);
    }

    public function createFutureSlot(): FutureSlot
    {
        return new TestFutureSlot();
    }

    public function spawn(callable $actorLoop): string
    {
        $id = 'test-actor-' . $this->spawnCounter++;
        $this->spawned[] = $actorLoop;

        return $id;
    }

    public function scheduleOnce(Duration $delay, callable $callback): Cancellable
    {
        $microseconds = (int) ($delay->toNanos() / 1000);
        $fireAt = $this->clock->now()->modify("+{$microseconds} microseconds");
        $cancellable = new TestCancellable();
        $this->timers[] = [$callback, $fireAt, false, null, $cancellable];

        return $cancellable;
    }

    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable
    {
        $microseconds = (int) ($initialDelay->toNanos() / 1000);
        $fireAt = $this->clock->now()->modify("+{$microseconds} microseconds");
        $cancellable = new TestCancellable();
        $this->timers[] = [$callback, $fireAt, true, $interval, $cancellable];

        return $cancellable;
    }

    public function yield(): void
    {
        // no-op in test
    }

    public function sleep(Duration $duration): void
    {
        $this->clock->advance($duration);
    }

    public function run(): void
    {
        $this->running = true;
    }

    public function shutdown(Duration $timeout): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    // -- Test helpers ---------------------------------------------------------

    public function clock(): TestClock
    {
        return $this->clock;
    }

    /**
     * Advance time and fire any due timers.
     */
    public function advanceTime(Duration $duration): void
    {
        $this->clock->advance($duration);
        $this->fireDueTimers();
    }

    /**
     * Fire all timers whose fire time <= now.
     */
    public function fireDueTimers(): void
    {
        $now = $this->clock->now();
        $remaining = [];

        foreach ($this->timers as $timer) {
            [$callback, $fireAt, $repeating, $interval, $cancellable] = $timer;

            if ($cancellable->isCancelled()) {
                continue;
            }

            if ($fireAt <= $now) {
                $callback();

                if ($repeating && $interval !== null) {
                    $intervalMicros = (int) ($interval->toNanos() / 1000);
                    $nextFire = $fireAt->modify("+{$intervalMicros} microseconds");
                    $remaining[] = [$callback, $nextFire, true, $interval, $cancellable];
                }
            } else {
                $remaining[] = $timer;
            }
        }

        $this->timers = $remaining;
    }

    /** @return list<callable> */
    public function spawnedActors(): array
    {
        return $this->spawned;
    }
}
