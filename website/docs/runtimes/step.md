---
sidebar_position: 4
title: Step runtime
---

# Step runtime

`StepRuntime` is a deterministic runtime designed for testing. It uses PHP
Fibers internally but gives tests full control over execution: process exactly
one message per `step()` call, advance virtual time to trigger timers, and
guarantee deterministic ordering across actors.

## Setup

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
$system = ActorSystem::create('test-system', $runtime, clock: $runtime->clock());
```

Pass `$runtime->clock()` to the actor system so actors see the same virtual
clock. Time never advances unless the test explicitly calls `advanceTime()`.

## Step API

### `step(): bool`

Process exactly one message from one actor. Returns `true` if a message was
processed, `false` if all actors are idle. Actors are checked in creation order,
making the processing order fully deterministic.

```php
$ref->tell(new Increment());
$ref->tell(new Increment());

$runtime->step(); // processes first Increment
$runtime->step(); // processes second Increment
$runtime->step(); // returns false — no more messages
```

### `drain(): void`

Process all pending messages until no actor has work to do. Equivalent to
calling `step()` in a loop until it returns `false`.

```php
$ref->tell(new Increment());
$ref->tell(new Increment());
$ref->tell(new Increment());

$runtime->drain(); // processes all three
```

### `advanceTime(Duration $duration): void`

Advance the virtual clock by the given duration and fire any timers that have
matured. Time does not advance on its own — the test controls it entirely.

```php
$runtime->scheduleOnce(Duration::seconds(5), function () {
    // fires when virtual time reaches 5 seconds
});

$runtime->advanceTime(Duration::seconds(3)); // timer not yet due
$runtime->advanceTime(Duration::seconds(3)); // timer fires (6 >= 5)
```

## Inspection methods

### `pendingMessageCount(): int`

Returns the total number of unprocessed messages across all actor mailboxes.

```php
$ref->tell(new Increment());
$ref->tell(new Increment());
assert($runtime->pendingMessageCount() === 2);

$runtime->step();
assert($runtime->pendingMessageCount() === 1);
```

### `isIdle(): bool`

Returns `true` when no actor has both a pending message and a waiting fiber.

### `clock(): VirtualClock`

Returns the virtual clock. Useful for assertions on time-dependent behavior.

## VirtualClock

`VirtualClock` implements PSR-20 `ClockInterface`. It starts at
`2026-01-01T00:00:00+00:00` by default and never advances unless told to.

```php
$clock = $runtime->clock();
$t1 = $clock->now();

$runtime->advanceTime(Duration::seconds(10));
$t2 = $clock->now();

// $t2 is exactly 10 seconds after $t1
```

The clock can also be manipulated directly:

```php
$clock->advance(Duration::seconds(300));
$clock->set(new DateTimeImmutable('2030-01-01T00:00:00+00:00'));
```

## Architecture

### How one-message-per-step works

The key difference from `FiberRuntime` is in the mailbox. `FiberMailbox`
suspends the fiber only when the queue is empty. `StepMailbox` **always**
suspends the fiber in `dequeueBlocking()`, even when messages are available:

```text
1. Actor fiber calls dequeueBlocking()
2. StepMailbox stores fiber reference, calls Fiber::suspend()
3. Control returns to the test
4. Test calls $runtime->step()
5. step() finds this mailbox has a message + waiting fiber → resumes fiber
6. Fiber wakes up, checks queue → message found → returns envelope
7. Actor processes message, loops back to dequeueBlocking() → goto step 2
```

This guarantees that every message requires an explicit `step()` call to be
processed. No messages are ever processed behind the test's back.

### Deterministic ordering

Mailboxes are stored in a list ordered by creation time. When `step()` looks
for the next message to process, it iterates this list from the beginning and
picks the first mailbox that has both a pending message and a waiting fiber.
Since actors are spawned in a deterministic order, processing is deterministic.

### Timers

Timers are stored with their virtual fire time. When `advanceTime()` is called,
the clock advances and all timers whose fire time has passed are executed.
Repeating timers are rescheduled from their previous fire time (not from "now"),
preventing drift.

## Testing patterns

### Step-by-step verification

```php
$ref->tell(new Increment());
$ref->tell(new Increment());
$ref->tell(new Increment());

$runtime->step();
// assert state after first message

$runtime->step();
// assert state after second message

$runtime->step();
// assert state after third message
```

### Cascading messages

When an actor sends a message to another actor during processing, the new
message becomes available on the next `step()` call:

```php
// forwarder receives message, tells receiver
$forwarderRef->tell($message);

$runtime->step();  // forwarder processes message, enqueues to receiver
$runtime->step();  // receiver processes the forwarded message
```

### Timer-driven behavior

```php
// Schedule a repeating timer
$runtime->scheduleRepeatedly(
    Duration::seconds(1),
    Duration::seconds(1),
    function () use ($ref) {
        $ref->tell(new Tick());
    },
);

$runtime->advanceTime(Duration::seconds(1)); // timer fires, Tick enqueued
$runtime->step();                             // actor processes Tick

$runtime->advanceTime(Duration::seconds(1)); // timer fires again
$runtime->step();                             // actor processes second Tick
```

### Cancelling timers

```php
$cancellable = $runtime->scheduleOnce(Duration::seconds(5), function () {
    // this will never fire
});

$cancellable->cancel();
$runtime->advanceTime(Duration::seconds(10)); // nothing happens
```

## Comparison with FiberRuntime

| Aspect | FiberRuntime | StepRuntime |
|---|---|---|
| Message processing | Automatic tick loop | Manual `step()` calls |
| Time | Real wall clock | Virtual clock |
| Ordering | Non-deterministic | Deterministic (creation order) |
| Timers | Real-time callbacks | Fire on `advanceTime()` |
| `yield()` / `sleep()` | Fiber suspension / `usleep()` | No-op |
| Use case | Development, production | Testing |
