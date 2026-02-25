---
sidebar_position: 4
title: Runtime Without Actors
---

# Runtime Without Actors

You can use `nexus-runtime` directly without bootstrapping `ActorSystem`.

This is useful when you want:

- `Future` composition (`map`, `flatMap`, `await`) in plain PHP workflows
- timer scheduling and cancellation primitives for lightweight orchestration
- a custom runtime contract for framework adapters or protocol bridges

## Install

```bash
composer require nexus-actors/runtime
```

## Core Contracts (Non-Actor View)

- `Monadial\Nexus\Runtime\Async\Future`
  - immutable handle to an async result
  - supports `await()`, `map()`, `flatMap()`
- `Monadial\Nexus\Runtime\Async\FutureSlot`
  - low-level slot that is `resolve()`/`fail()`-ed by producer side
- `Monadial\Nexus\Runtime\Runtime\Runtime`
  - runtime contract for scheduling, task orchestration, and lifecycle control

When used without actors, `createMailbox()` and `spawn()` can still exist but
may be minimal/no-op in your implementation if mailboxes are not part of your
workflow.

## Full Custom Runtime Walkthrough

This example shows a simple in-memory runtime implementation that:

- schedules one-shot and repeating callbacks
- supports `FutureSlot` creation
- provides a `run()` loop and graceful `shutdown()`

```php
<?php

declare(strict_types=1);

namespace App\Runtime;

use DateTimeImmutable;
use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use RuntimeException;
use Throwable;

final class InlineCancellable implements Cancellable
{
    private bool $cancelled = false;

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}

final class InlineFutureSlot implements FutureSlot
{
    private ?object $value = null;
    private ?Throwable $failure = null;
    private bool $resolved = false;

    public function resolve(object $value): void
    {
        if ($this->resolved) {
            return;
        }

        $this->value = $value;
        $this->resolved = true;
    }

    public function fail(Throwable $e): void
    {
        if ($this->resolved) {
            return;
        }

        $this->failure = $e;
        $this->resolved = true;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function await(): object
    {
        while (!$this->resolved) {
            usleep(1000);
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->value ?? throw new RuntimeException('Future resolved without value');
    }
}

final class MinimalRuntime implements Runtime
{
    /** @var list<array{at: DateTimeImmutable, cb: callable, intervalMicros: int|null, cancellable: InlineCancellable}> */
    private array $timers = [];

    private bool $running = false;
    private bool $shutdown = false;

    public function name(): string
    {
        return 'minimal-runtime';
    }

    public function createMailbox(MailboxConfig $config): Mailbox
    {
        throw new RuntimeException('Mailbox is not used in this runtime-only workflow');
    }

    public function createFutureSlot(): FutureSlot
    {
        return new InlineFutureSlot();
    }

    public function spawn(callable $actorLoop): string
    {
        // No actor fibers/coroutines in this simplified runtime.
        // You can adapt this to enqueue tasks if needed.
        return 'task-0';
    }

    public function scheduleOnce(Duration $delay, callable $callback): Cancellable
    {
        $timer = new InlineCancellable();
        $at = $this->now()->modify('+' . $delay->toMicros() . ' microseconds');
        $this->timers[] = ['at' => $at, 'cb' => $callback, 'intervalMicros' => null, 'cancellable' => $timer];

        return $timer;
    }

    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable
    {
        $timer = new InlineCancellable();
        $at = $this->now()->modify('+' . $initialDelay->toMicros() . ' microseconds');
        $this->timers[] = ['at' => $at, 'cb' => $callback, 'intervalMicros' => $interval->toMicros(), 'cancellable' => $timer];

        return $timer;
    }

    public function yield(): void
    {
        usleep(100);
    }

    public function sleep(Duration $duration): void
    {
        usleep($duration->toMicros());
    }

    public function run(): void
    {
        $this->running = true;
        $this->shutdown = false;

        while ($this->running) {
            $this->tickTimers();

            if ($this->shutdown) {
                $this->running = false;
                break;
            }

            usleep(500);
        }
    }

    public function shutdown(Duration $timeout): void
    {
        $this->shutdown = true;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    private function tickTimers(): void
    {
        $now = $this->now();
        $next = [];

        foreach ($this->timers as $timer) {
            if ($timer['cancellable']->isCancelled()) {
                continue;
            }

            if ($timer['at'] <= $now) {
                ($timer['cb'])();

                if ($timer['intervalMicros'] !== null && !$timer['cancellable']->isCancelled()) {
                    $timer['at'] = $timer['at']->modify('+' . $timer['intervalMicros'] . ' microseconds');
                    $next[] = $timer;
                }
            } else {
                $next[] = $timer;
            }
        }

        $this->timers = $next;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
```

## Standalone Future Usage

Now use that runtime without actors:

```php
use App\Runtime\MinimalRuntime;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Async\Future;

$runtime = new MinimalRuntime();
$slot = $runtime->createFutureSlot();
$future = new Future($slot);

$runtime->scheduleOnce(Duration::millis(25), static function () use ($slot): void {
    $slot->resolve((object) ['value' => 21]);
});

$runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
    $runtime->shutdown(Duration::seconds(1));
});

$runtime->run();

$result = $future
    ->map(static fn(object $v): object => (object) ['value' => $v->value * 2])
    ->await();
```

## Timeout / Failure Path Example

```php
use Monadial\Nexus\Core\Exception\AskTimeoutException;

$slot = $runtime->createFutureSlot();
$future = new Future($slot);

$runtime->scheduleOnce(Duration::millis(100), static function () use ($slot): void {
    $slot->fail(new RuntimeException('timeout-like failure'));
});

$runtime->scheduleOnce(Duration::millis(150), static function () use ($runtime): void {
    $runtime->shutdown(Duration::seconds(1));
});

$runtime->run();

try {
    $future->await();
} catch (RuntimeException $e) {
    // handle timeout/failure mapping here
}
```

## Practical Constraints

- `await()` blocks current execution until resolved/failed.
- busy-wait loops in example code are intentionally simple; production runtimes
  should suspend/resume efficiently.
- keep timer callbacks short to avoid starving other scheduled work.
- if you later need mailboxes and actor lifecycle, move to `nexus-core` +
  runtime implementation packages.

## Next

- Fast setup: [Bootstrap Runtime](./bootstrap.md)
- Runtime contracts and implementation matrix: [Runtime Overview](./overview.md)
- Package surface: [nexus-runtime](../packages/runtime.md)
