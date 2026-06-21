---
sidebar_position: 6
title: Step Runtime
related:
  - runtimes/overview
  - runtimes/fiber
  - runtimes/bootstrap
  - core-concepts/actors
---

# Step Runtime

`StepRuntime` is a deterministic runtime designed for testing — it uses PHP fibers internally but gives tests full control over execution: process exactly one message per `step()` call, advance virtual time on demand, and guarantee message ordering across all actors.

## The design

The key difference from `FiberRuntime` is in the mailbox. `FiberMailbox` suspends the actor's fiber only when the queue is empty. `StepMailbox` always suspends in `dequeueBlocking()`, even when messages are waiting:

1. Actor fiber calls `dequeueBlocking()`.
2. `StepMailbox` stores the fiber reference and calls `Fiber::suspend()`.
3. Control returns to the test.
4. Test calls `$runtime->step()`.
5. `step()` finds a mailbox with a pending message and a waiting fiber — it resumes the fiber.
6. Fiber wakes up, dequeues the message, and processes it.
7. Actor loops back to `dequeueBlocking()` — goto step 2.

This guarantees that every message requires an explicit `step()` call. No messages are processed behind the test's back.

**Deterministic ordering** — mailboxes are stored in a list ordered by actor creation time. When `step()` searches for the next message to process, it iterates from the beginning and picks the first mailbox that has both a pending message and a waiting fiber. Spawning actors in a fixed order produces fixed processing order.

**Timers** — stored with their virtual fire time. When `advanceTime()` is called, the clock advances and all timers whose fire time has passed are executed. Repeating timers are rescheduled from their previous fire time, not from "now", so they never drift.

## Setup

Pass `$runtime->clock()` to `ActorSystem::create()` so actors share the same virtual clock as the test.

```php title="tests/bootstrap-step.php"
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
$system = ActorSystem::create('test-system', $runtime, clock: $runtime->clock());
```

Time never advances unless the test calls `advanceTime()`.

## Step API

### `step(): bool`

Process exactly one message from one actor. Returns `true` if a message was processed, `false` if all actors are idle.

```php title="tests/Actor/CounterActorTest.php"
$ref->tell(new Increment());
$ref->tell(new Increment());

$runtime->step(); // processes first Increment
$runtime->step(); // processes second Increment
$runtime->step(); // returns false — no more messages
```

### `drain(): void`

Process all pending messages until no actor has work to do. Equivalent to calling `step()` in a loop until it returns `false`.

```php title="tests/Actor/CounterActorTest.php"
$ref->tell(new Increment());
$ref->tell(new Increment());
$ref->tell(new Increment());

$runtime->drain(); // processes all three
```

### `advanceTime(Duration $duration): void`

Advance the virtual clock by the given duration and fire any timers that have matured.

```php title="tests/Actor/SchedulerTest.php"
$runtime->scheduleOnce(Duration::seconds(5), function () {
    // fires when virtual time reaches 5 seconds
});

$runtime->advanceTime(Duration::seconds(3)); // timer not yet due
$runtime->advanceTime(Duration::seconds(3)); // timer fires (accumulated: 6 >= 5)
```

## Inspection

- `pendingMessageCount(): int` — total unprocessed messages across all actor mailboxes.
- `isIdle(): bool` — `true` when no actor has both a pending message and a waiting fiber.
- `clock(): VirtualClock` — returns the virtual clock for assertions on time-dependent behavior.

## VirtualClock

`VirtualClock` implements PSR-20 `ClockInterface`. It starts at `2026-01-01T00:00:00+00:00` by default and never advances on its own.

```php title="tests/Actor/TimeTest.php"
$clock = $runtime->clock();
$t1 = $clock->now();

$runtime->advanceTime(Duration::seconds(10));
$t2 = $clock->now();

// $t2 is exactly 10 seconds after $t1
```

You can also manipulate the clock directly:

```php title="tests/Actor/TimeTest.php"
$clock->advance(Duration::seconds(300));
$clock->set(new DateTimeImmutable('2030-01-01T00:00:00+00:00'));
```

## Testing patterns

### Step-by-step verification

Assert state after each individual message — this is the primary reason to reach for `StepRuntime`.

```php title="tests/Actor/CounterActorTest.php"
$ref->tell(new Increment());
$ref->tell(new Increment());
$ref->tell(new Increment());

$runtime->step();
self::assertSame(1, $counter->value());

$runtime->step();
self::assertSame(2, $counter->value());

$runtime->step();
self::assertSame(3, $counter->value());
```

### Cascading messages

When an actor sends a message to another actor during processing, the new message becomes available on the next `step()` call.

```php title="tests/Actor/ForwarderTest.php"
$forwarderRef->tell($message);

$runtime->step(); // forwarder processes, enqueues to receiver
$runtime->step(); // receiver processes the forwarded message
```

### Timer-driven behavior

```php title="tests/Actor/TickActorTest.php"
$runtime->scheduleRepeatedly(
    Duration::seconds(1),
    Duration::seconds(1),
    function () use ($ref): void {
        $ref->tell(new Tick());
    },
);

$runtime->advanceTime(Duration::seconds(1));
$runtime->step(); // actor processes first Tick

$runtime->advanceTime(Duration::seconds(1));
$runtime->step(); // actor processes second Tick
```

## Comparison with FiberRuntime

| Aspect | `FiberRuntime` | `StepRuntime` |
|---|---|---|
| Message processing | Automatic tick loop | Manual `step()` calls |
| Time | Real wall clock | Virtual clock |
| Ordering | Non-deterministic | Deterministic (creation order) |
| Timers | Real-time callbacks | Fire on `advanceTime()` |
| `yield()` / `sleep()` | Fiber suspension / `usleep()` | No-op |
| Use case | Development, production | Testing |

## Tradeoffs

`StepRuntime` makes actor tests deterministic and assertion-friendly at the cost of real-time execution. Every message requires an explicit `step()`. Timer resolution is infinite — virtual time jumps by exactly the amount you specify. This makes testing timer-driven logic trivial, but rules it out for any production use where real time matters.

## When to reach for it

- Testing actor logic that depends on message ordering.
- Asserting intermediate state after exactly N messages.
- Testing timer-triggered behavior without sleeping in CI.
- Building test harnesses that need to pause and inspect the actor system between messages.

## See also

- [Fiber Runtime](./fiber.md) — the production-oriented alternative for development and CI
- [Runtime Overview](./overview.md) — choosing between all three runtimes
- [Bootstrap](./bootstrap.md) — wiring `StepRuntime` into an `ActorSystem` in test setup
