---
title: Duration
sidebar_position: 1
related:
  - core-concepts/actors
  - reference/classes/actor-ref
  - reference/classes/actor-system
---

# Duration

Nanosecond-precision immutable duration value object.

## What it does

`Duration` is the standard way to express time spans throughout Nexus — timeouts,
scheduled delays, backoff windows, and blocking mailbox calls all accept a `Duration`.
The value is stored as a single `int64` nanosecond count, giving zero-overhead
conversions in hot paths without floating-point drift. All arithmetic methods return
a new instance; the original is never mutated. Because it implements `Stringable` you
can also log it directly as a human-readable string such as `"5.600s"`.

## Example

```php title="src/Scheduler.php"
use Monadial\Nexus\Runtime\Duration;

$timeout = Duration::seconds(5);
$backoff  = Duration::millis(200)->multipliedBy(3);   // 600 ms
$total    = $timeout->plus($backoff);                  // 5 s 600 ms

// Use as a request-response timeout
$count = $ref->ask(new GetCount(), $timeout)->await();

// Use as a one-shot schedule delay
$ctx->scheduleOnce(Duration::millis(500), fn() => $ctx->self()->tell(new Tick()));

// Inspect the value
echo $total->toMillis();       // 5600
echo $total->toSecondsFloat(); // 5.6
echo (string) $total;          // "5.600s"
```

## Key methods

- `Duration::seconds(int $n): self` — construct from whole seconds.
- `Duration::millis(int $n): self` — construct from milliseconds.
- `Duration::micros(int $n): self` — construct from microseconds.
- `Duration::nanos(int $n): self` — construct from nanoseconds.
- `Duration::zero(): self` — the zero-length duration.
- `plus(Duration $other): self` — add two durations.
- `minus(Duration $other): self` — subtract.
- `multipliedBy(int $factor): self` — scale by an integer factor.
- `dividedBy(int $divisor): self` — divide (integer division of nanos).
- `toNanos(): int` — raw nanosecond count.
- `toMillis(): int` — truncated millisecond count.
- `toSecondsFloat(): float` — seconds as a floating-point number.
- `isGreaterThan(Duration $other): bool` / `isLessThan(Duration $other): bool` — comparisons.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Runtime-Duration.html)

## See also

- [ActorRef](actor-ref) — `ask()` and `tell()` accept `Duration` timeouts
- [ActorSystem](actor-system) — `shutdown(Duration $timeout)` drains within the given window
- [Core Concepts — Actors](../../core-concepts/actors) — where timers and timeouts arise
