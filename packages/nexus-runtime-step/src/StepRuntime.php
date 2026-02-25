<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Step;

use DateTimeImmutable;
use Fiber;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Actor\FutureSlot;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Runtime\Runtime;
use Override;

/**
 * Deterministic runtime for testing.
 *
 * Uses PHP Fibers internally but gives the test full control over execution:
 * - step() processes exactly one message
 * - drain() processes all pending messages
 * - advanceTime() moves virtual clock and fires timers
 *
 * No real concurrency, no real time, fully deterministic.
 *
 * @psalm-api
 */
final class StepRuntime implements Runtime
{
    private readonly VirtualClock $clock;

    /** @var array<string, Fiber<mixed, mixed, mixed, mixed>> */
    private array $fibers = [];

    /** @var list<StepMailbox> */
    private array $mailboxes = [];

    /** @var list<array{callable, DateTimeImmutable, bool, Duration|null, StepCancellable}> */
    private array $timers = [];

    private int $nextId = 0;

    private bool $running = false;

    public function __construct(?VirtualClock $clock = null)
    {
        $this->clock = $clock ?? new VirtualClock();
    }

    // -- Runtime interface --------------------------------------------------------

    #[Override]
    public function name(): string
    {
        return 'step';
    }

    #[Override]
    public function createMailbox(MailboxConfig $config): Mailbox
    {
        $mailbox = new StepMailbox($config, ActorPath::root());
        $this->mailboxes[] = $mailbox;

        return $mailbox;
    }

    #[Override]
    public function createFutureSlot(Duration $timeout): FutureSlot
    {
        $slot = new StepFutureSlot();

        $this->scheduleOnce($timeout, static function () use ($slot, $timeout): void {
            $slot->fail(new AskTimeoutException(ActorPath::fromString('/temp/ask'), $timeout));
        });

        return $slot;
    }

    #[Override]
    public function spawn(callable $actorLoop): string
    {
        $id = 'step-' . $this->nextId++;

        /** @var Fiber<mixed, mixed, mixed, mixed> */
        $fiber = new Fiber($actorLoop);
        $this->fibers[$id] = $fiber;

        return $id;
    }

    #[Override]
    public function scheduleOnce(Duration $delay, callable $callback): Cancellable
    {
        $fireAt = $this->addDuration($this->clock->now(), $delay);
        $cancellable = new StepCancellable();
        $this->timers[] = [$callback, $fireAt, false, null, $cancellable];

        return $cancellable;
    }

    #[Override]
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable
    {
        $fireAt = $this->addDuration($this->clock->now(), $initialDelay);
        $cancellable = new StepCancellable();
        $this->timers[] = [$callback, $fireAt, true, $interval, $cancellable];

        return $cancellable;
    }

    #[Override]
    public function yield(): void
    {
        // no-op — deterministic runtime, no cooperative scheduling needed
    }

    #[Override]
    public function sleep(Duration $duration): void
    {
        // no-op — tests control time via advanceTime()
    }

    #[Override]
    public function run(): void
    {
        $this->running = true;
        $this->drain();
        $this->running = false;
    }

    #[Override]
    public function shutdown(Duration $timeout): void
    {
        $this->running = false;

        foreach ($this->mailboxes as $mailbox) {
            $mailbox->close();
        }

        $this->cleanupTerminatedFibers();
    }

    #[Override]
    public function isRunning(): bool
    {
        return $this->running;
    }

    // -- Step API -----------------------------------------------------------------

    /**
     * Process exactly one message from one actor.
     *
     * Returns true if a message was processed, false if all actors are idle.
     * Actors are checked in creation order (deterministic).
     */
    public function step(): bool
    {
        $this->startPendingFibers();

        foreach ($this->mailboxes as $mailbox) {
            if (!$mailbox->isEmpty() && $mailbox->hasWaitingFiber()) {
                $fiber = $mailbox->getWaitingFiber();

                if ($fiber !== null && $fiber->isSuspended()) {
                    $fiber->resume();
                    $this->cleanupTerminatedFibers();

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Process all pending messages until no actor has work to do.
     */
    public function drain(): void
    {
        while ($this->step()) {
            // keep processing
        }
    }

    /**
     * Advance the virtual clock and fire any timers that have matured.
     */
    public function advanceTime(Duration $duration): void
    {
        $this->clock->advance($duration);
        $this->fireDueTimers();
    }

    // -- Inspection ---------------------------------------------------------------

    public function clock(): VirtualClock
    {
        return $this->clock;
    }

    public function pendingMessageCount(): int
    {
        $count = 0;

        foreach ($this->mailboxes as $mailbox) {
            $count += $mailbox->count();
        }

        return $count;
    }

    public function isIdle(): bool
    {
        foreach ($this->mailboxes as $mailbox) {
            if (!$mailbox->isEmpty() && $mailbox->hasWaitingFiber()) {
                return false;
            }
        }

        return true;
    }

    // -- Internal -----------------------------------------------------------------

    private function startPendingFibers(): void
    {
        foreach ($this->fibers as $id => $fiber) {
            if (!$fiber->isStarted()) {
                $fiber->start();

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$id]);
                }
            }
        }
    }

    private function cleanupTerminatedFibers(): void
    {
        foreach ($this->fibers as $id => $fiber) {
            if ($fiber->isTerminated()) {
                unset($this->fibers[$id]);
            }
        }
    }

    private function fireDueTimers(): void
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
                    $nextFire = $this->addDuration($fireAt, $interval);
                    $remaining[] = [$callback, $nextFire, true, $interval, $cancellable];
                }
            } else {
                $remaining[] = $timer;
            }
        }

        $this->timers = $remaining;
    }

    private function addDuration(DateTimeImmutable $time, Duration $duration): DateTimeImmutable
    {
        $microseconds = (int) ($duration->toNanos() / 1000);
        $modified = $time->modify("+{$microseconds} microseconds");

        return $modified !== false
            ? $modified
            : $time;
    }
}
