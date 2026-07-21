---
sidebar_position: 5
title: Mailboxes
related:
  - core-concepts/actors
  - core-concepts/lifecycle
  - core-concepts/ask-pattern
  - core-concepts/props
---

# Mailboxes

Every actor has a mailbox — a queue that buffers incoming messages until the actor is ready to process them. `MailboxConfig` controls capacity and overflow behavior. Every message is wrapped in an `Envelope` that carries the sender path, target path, and distributed tracing identifiers alongside the payload.

## MailboxConfig

`MailboxConfig` is a `final readonly class`. Instances come from named constructors.

### Unbounded mailbox

The default. No configured capacity limit; the mailbox grows as needed.

```php title="src/Actor/UnboundedExample.php"
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;

$config = MailboxConfig::unbounded();
```

:::note Swoole physical capacity
On the Swoole runtime, "unbounded" mailboxes are backed by a coroutine channel with a physical capacity of **65,536 messages** (exposed as `SwooleMailbox::$effectiveCapacity`). Admission is truthful: reaching the physical cap throws `MailboxOverflowException` rather than silently losing messages, and `isFull()` reports the real channel state. The Fiber runtime's unbounded mailbox has no such cap.
:::

### Bounded mailbox

A fixed-capacity mailbox with a configurable overflow strategy.

```php title="src/Actor/BoundedExample.php"
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

$config = MailboxConfig::bounded(1000, OverflowStrategy::DropOldest);
```

Both `withCapacity()` and `withStrategy()` return new `MailboxConfig` instances — the original is never mutated:

```php title="src/Actor/FluentMailboxConfig.php"
$config = MailboxConfig::bounded(500)
    ->withCapacity(1000)
    ->withStrategy(OverflowStrategy::Backpressure);
```

## OverflowStrategy

The `OverflowStrategy` enum determines what happens when a bounded mailbox is full and a new message arrives.

| Strategy | Effect |
|---|---|
| `OverflowStrategy::ThrowException` | Throw `MailboxOverflowException`. This is the default. |
| `OverflowStrategy::DropNewest` | Discard the incoming message. The mailbox is unchanged. |
| `OverflowStrategy::DropOldest` | Remove the oldest queued message to make room for the new one. |
| `OverflowStrategy::Backpressure` | Block the sender until space is available. |

Strategy selection depends on the actor's requirements:

- **ThrowException** — fail fast; useful during development or when overflow indicates a design problem.
- **DropNewest** — acceptable when the latest data supersedes older data (sensor readings, status updates).
- **DropOldest** — acceptable when recent messages must be processed rather than older ones.
- **Backpressure** — the sender slows to match the consumer's pace; prevents message loss but may stall upstream actors.

## Applying a mailbox configuration

Attach a mailbox configuration to an actor through `Props::withMailbox()`.

```php title="src/Actor/BoundedActor.php"
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

$props = Props::fromBehavior($behavior)->withMailbox(
    MailboxConfig::bounded(500, OverflowStrategy::DropOldest),
);

$ref = $system->spawn($props, 'bounded-actor');
```

When no mailbox configuration is specified, `Props::fromBehavior()` defaults to `MailboxConfig::unbounded()`.

## Envelope

`Envelope` is a `final readonly class` that wraps every message with routing information and distributed tracing identifiers.

```php title="src/Mailbox/EnvelopeExample.php"
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Actor\ActorPath;

$envelope = Envelope::of($myMessage, $senderPath, $targetPath);
```

| Property | Type | Description |
|---|---|---|
| `message` | `object` | The actual message payload |
| `sender` | `ActorPath` | Path of the sending actor |
| `target` | `ActorPath` | Path of the receiving actor |
| `requestId` | `string` (ULID) | Unique identifier for this specific message delivery |
| `correlationId` | `string` (ULID) | Links all messages in the same logical operation |
| `causationId` | `string` (ULID) | Points to the `requestId` of the message that caused this one |
| `metadata` | `array<string, string>` | Arbitrary key-value metadata |

`Envelope::of()` creates a root envelope where all three IDs are set to the same fresh ULID. `ActorContext::reply()` propagates tracing identifiers automatically. Calling `$ref->tell()` directly on an `ActorRef` creates a root envelope and breaks the trace chain.

All modifier methods return a new `Envelope` — the original is unmodified:

```php title="src/Mailbox/EnvelopeModifiers.php"
$updated    = $envelope->withMetadata(['key' => 'value']);
$redirected = $envelope->withSender($newSenderPath);
```

## Mailbox interface

The `Mailbox` interface defines the contract that runtime implementations must fulfill.

```php title="src/Mailbox/Mailbox.php"
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Duration;

/** @template T of object */
interface Mailbox
{
    public function enqueue(object $message): EnqueueResult;
    public function dequeue(): mixed;
    public function dequeueBlocking(Duration $timeout): object;
    public function count(): int;
    public function isFull(): bool;
    public function isEmpty(): bool;
    public function close(): void;
    public function isClosed(): bool;
}
```

- `enqueue()` is marked `#[NoDiscard]` — the `EnqueueResult` must be inspected. Throws `MailboxClosedException` if the mailbox has been closed.
- `dequeueBlocking()` suspends the current fiber or coroutine until a message arrives or the timeout elapses. Throws `MailboxClosedException` if the mailbox is closed while waiting.
- `close()` permanently shuts down the mailbox. Subsequent `enqueue()` calls throw `MailboxClosedException`. Blocked `dequeueBlocking()` waiters are woken so the actor message loop can exit during system shutdown.

### EnqueueResult

| Value | Meaning |
|---|---|
| `EnqueueResult::Accepted` | Message successfully added. |
| `EnqueueResult::Dropped` | Message discarded (`DropNewest` or `DropOldest` strategy). |
| `EnqueueResult::Backpressured` | Sender blocked until space became available. |

## Failure modes

Mailbox failures are often silent — dropped messages leave no trace unless you inspect enqueue results or dead letters.

| Symptom | Cause | Recovery |
|---|---|---|
| `MailboxClosedException` thrown on `enqueue()` | Message was sent to an actor whose mailbox is already closed (actor stopped) | Check `$ref->isAlive()` before sending; handle dead letters via `$system->deadLetters()` |
| `MailboxOverflowException` thrown | Bounded mailbox is full and `OverflowStrategy::ThrowException` is active | Increase mailbox capacity, switch to a drop or backpressure strategy, or slow down the sender |
| `MailboxClosedException` during `dequeueBlocking()` | The mailbox was closed while the actor was waiting for a message | This is normal during shutdown; the actor message loop exits cleanly — no action needed |
| Messages silently disappear | `DropNewest` or `DropOldest` strategy is active and the mailbox filled | Inspect `EnqueueResult::Dropped` from `enqueue()`; increase capacity or reduce producer rate |
| `StashOverflowException` thrown | `Behavior::withStash()` buffer reached its declared capacity | Increase the stash capacity argument, or drain the stash sooner by handling the trigger message earlier |

## Next steps

- [Actors](./actors.md) — how `ActorRef::tell()` routes messages into the mailbox
- [Ask pattern](./ask-pattern.md) — how `ask()` uses the mailbox for request-reply
- [Props](./props.md) — attaching `MailboxConfig` at spawn time
