---
title: Dead letters
related:
  - core-concepts/mailboxes
  - core-concepts/lifecycle
  - core-concepts/ask-pattern
  - core-concepts/supervision
---

# Dead letters

When a message cannot be delivered to its intended recipient, Nexus routes it to the dead-letter stream rather than silently discarding it. Dead letters are the observable signal that messages are being lost — inspecting them is the first step in diagnosing delivery problems.

## The design

`DeadLetterRef` is a null-object `ActorRef` that lives at the path `/system/deadLetters`. Every `ActorSystem` owns one, accessible via `$system->deadLetters()`.

A message becomes a dead letter in three situations:

1. **Target actor has stopped** — the actor processed a `PoisonPill` or was stopped by its parent before the message arrived. The mailbox is closed; the message is forwarded to dead letters.
2. **`ask()` timeout** — the timeout fires before the target replies. The pending `FutureRef` is cancelled; the eventual reply (if it ever arrives) is forwarded to dead letters because the slot is already resolved.
3. **Enqueued to a terminated ref directly** — calling `tell()` on an `ActorRef` that has already transitioned to stopped state routes the message through `DeadLetterRef`.

Internally, `DeadLetterRef::tell()` appends the message to an in-process list. In tests you retrieve this list with `$system->deadLetters()->captured()`. In production, wire a `DeadLetter` system-message listener to your PSR-3 logger or metrics exporter to make dead letters observable.

## Inspecting dead letters in tests

<!-- verify:skip: illustrates a full ActorSystem runtime interaction that requires a running Fiber scheduler -->
```php title="tests/Integration/DeadLetterTest.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Runtime\Duration;

$runtime = new FiberRuntime();
$system  = ActorSystem::create('test', $runtime);

$ref = $system->spawn(Props::fromBehavior(Behavior::stopped()), 'target');

// Actor starts then immediately stops; subsequent tell goes to dead letters
$ref->tell(new MyMessage());

$runtime->scheduleOnce(Duration::millis(100), fn() => $system->shutdown(Duration::seconds(1)));
$system->run();

$dead = $system->deadLetters()->captured();
assert(count($dead) === 1);
```

## Monitoring dead letters in production

`DeadLetter` is a system message. Subscribe to it via a PSR-14 event listener to wire alerts:

```php title="src/Bootstrap/DeadLetterLogger.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Message\DeadLetter;
use Psr\Log\LoggerInterface;

final class DeadLetterLogger
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(DeadLetter $event): void
    {
        $this->logger->warning('Dead letter received', [
            'message'   => $event->message::class,
            'sender'    => (string) $event->sender->path(),
            'recipient' => (string) $event->recipient->path(),
        ]);
    }
}
```

## Failure modes

Dead letters are a symptom, not a cause. The table below maps the symptoms you observe to the underlying cause and the recovery path.

| Symptom | Cause | Recovery |
|---|---|---|
| Message never processed; appears in `deadLetters()->captured()` | Target actor stopped between the `tell()` call and mailbox dequeue | Use death watch (`$ctx->watch($ref)`) and handle `Terminated` to retry or reroute |
| `AskTimeoutException` thrown by `->await()` | Reply arrived after the ask timeout fired; original `FutureRef` was already cancelled | Call `$ref->isAlive()` before `ask()`; increase the `Duration` timeout; check the target actor for blocking calls |
| Messages vanish silently at scale with no exception | Bounded mailbox at capacity with `DropNewest` or `DropOldest` overflow strategy | Switch to `OverflowStrategy::Backpressure` or increase mailbox capacity; dead letters will capture dropped messages |
| Unexpected dead letters during graceful shutdown | `shutdown()` closed mailboxes before all in-flight messages were processed | Call `$system->shutdown()` with a larger `Duration` to allow the drain to complete |

## When to reach for it

- Use `$system->deadLetters()->captured()` in integration tests to assert that no messages were lost.
- Subscribe a PSR-14 listener to `DeadLetter` in production to drive alerts and metrics.
- A sudden spike in dead letters after a deployment indicates actors are stopping unexpectedly — check supervision logs.

## See also

- [Mailboxes](./mailboxes.md) — overflow strategies that route dropped messages to dead letters.
- [Lifecycle](./lifecycle.md) — when actors stop and how `PoisonPill` triggers the transition.
- [Ask pattern](./ask-pattern.md) — how ask timeouts produce dead letters from late replies.
- [Supervision](./supervision.md) — how to prevent actors from stopping unexpectedly under failure.
