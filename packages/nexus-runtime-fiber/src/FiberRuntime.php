<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use Closure;
use DateTimeImmutable;
use Fiber;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Runtime\Runtime;
use Override;

/** @psalm-api */
final class FiberRuntime implements Runtime
{
    private FiberScheduler $scheduler;

    /** @var array<string, Fiber<mixed, mixed, mixed, mixed>> */
    private array $fibers = [];

    private int $nextId = 0;

    private bool $running = false;

    private bool $shutdownRequested = false;

    private bool $wakeupPending = false;

    public function __construct()
    {
        $this->scheduler = new FiberScheduler();
    }

    #[Override]
    public function name(): string
    {
        return 'fiber';
    }

    #[Override]
    public function createMailbox(MailboxConfig $config): Mailbox
    {
        return new FiberMailbox($config, ActorPath::root(), function (): void {
            $this->wakeupPending = true;
        });
    }

    #[Override]
    public function spawn(callable $actorLoop): string
    {
        $id = 'fiber-' . $this->nextId++;

        /** @var Fiber<mixed, mixed, mixed, mixed> */
        $fiber = new Fiber($actorLoop);
        $this->fibers[$id] = $fiber;

        return $id;
    }

    #[Override]
    public function scheduleOnce(Duration $delay, callable $callback): Cancellable
    {
        /** @var Closure():void $closure */
        $closure = $callback(...);

        return $this->scheduler->scheduleOnce($delay, $closure, $this->now());
    }

    #[Override]
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable
    {
        /** @var Closure():void $closure */
        $closure = $callback(...);

        return $this->scheduler->scheduleRepeatedly($initialDelay, $interval, $closure, $this->now());
    }

    #[Override]
    public function yield(): void
    {
        if (Fiber::getCurrent() !== null) {
            Fiber::suspend('yield');
        }
    }

    #[Override]
    public function sleep(Duration $duration): void
    {
        $micros = $duration->toMicros();

        if ($micros > 0) {
            usleep($micros);
        }
    }

    #[Override]
    public function run(): void
    {
        $this->running = true;
        $this->shutdownRequested = false;

        /** @psalm-suppress RedundantCondition -- running is mutated by tick()/hasWork() */
        while ($this->running) {
            $this->tick();
            $this->scheduler->advanceTimers($this->now());

            /** @psalm-suppress TypeDoesNotContainType -- shutdownRequested is mutated externally */
            if ($this->shutdownRequested && $this->allFibersComplete()) {
                $this->running = false;

                break;
            }

            if (!$this->hasWork()) {
                $this->running = false;

                break;
            }

            // Only sleep when no messages were enqueued during this tick
            if ($this->wakeupPending) {
                $this->wakeupPending = false;
            } else {
                usleep(100);
            }
        }
    }

    #[Override]
    public function shutdown(Duration $timeout): void
    {
        $this->shutdownRequested = true;
    }

    #[Override]
    public function isRunning(): bool
    {
        return $this->running;
    }

    private function tick(): void
    {
        foreach ($this->fibers as $id => $fiber) {
            if ($fiber->isTerminated()) {
                unset($this->fibers[$id]);
            } elseif (!$fiber->isStarted()) {
                $fiber->start();

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$id]);
                }
            } elseif ($fiber->isSuspended()) {
                $fiber->resume();

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$id]);
                }
            }
        }
    }

    private function allFibersComplete(): bool
    {
        return $this->fibers === [];
    }

    private function hasWork(): bool
    {
        if ($this->fibers !== []) {
            return true;
        }

        return $this->scheduler->hasPendingTimers();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
