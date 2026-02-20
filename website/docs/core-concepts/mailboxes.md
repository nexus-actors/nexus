---
sidebar_position: 5
title: Mailboxes
---

# Mailboxes

Every actor has a mailbox -- a queue that buffers incoming messages until the actor
is ready to process them. Nexus provides control over mailbox capacity and
overflow behavior through `MailboxConfig`, and wraps every message in an
`Envelope` that carries sender, target, and metadata alongside the payload.

## MailboxConfig

`MailboxConfig` is a `final readonly class` with a private constructor. Instances
are created through named constructors.

### Unbounded mailbox

The default. No capacity limit. The mailbox grows as needed.

```php
use Monadial\Nexus\Core\Mailbox\MailboxConfig;

$config = MailboxConfig::unbounded();
```

Internally this sets capacity to `PHP_INT_MAX` and marks the mailbox as unbounded.

### Bounded mailbox

A fixed-capacity mailbox with a configurable overflow strategy.

```php
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;

$config = MailboxConfig::bounded(1000);

$config = MailboxConfig::bounded(1000, OverflowStrategy::DropOldest);
```

**Signature:**

```php
public static function bounded(
    int $capacity,
    OverflowStrategy $strategy = OverflowStrategy::ThrowException,
): MailboxConfig
```

### Fluent modifiers

Both `withCapacity()` and `withStrategy()` return new `MailboxConfig` instances
(the original is never mutated):

```php
$config = MailboxConfig::bounded(500)
    ->withCapacity(1000)
    ->withStrategy(OverflowStrategy::Backpressure);
```

## OverflowStrategy

The `OverflowStrategy` enum determines what happens when a bounded mailbox is
full and a new message arrives:

| Strategy | Effect |
|---|---|
| `OverflowStrategy::DropNewest` | Discard the incoming message. The mailbox is unchanged. |
| `OverflowStrategy::DropOldest` | Remove the oldest queued message to make room for the new one. |
| `OverflowStrategy::Backpressure` | Block the sender until space is available. |
| `OverflowStrategy::ThrowException` | Throw a `MailboxOverflowException`. This is the default. |

Choose a strategy based on your requirements:

- **DropNewest** -- acceptable when latest data supersedes older data (sensor
  readings, status updates).
- **DropOldest** -- acceptable when you always want the most recent messages
  processed.
- **Backpressure** -- the sender slows down to match the consumer's pace.
  Prevents message loss but may stall upstream actors.
- **ThrowException** -- fail fast. Useful during development or when overflow
  indicates a design problem.

## Envelope

`Envelope` is a `final readonly class` that wraps every message with routing
information and metadata.

```php
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Actor\ActorPath;

$envelope = new Envelope(
    message: $myMessage,
    sender: $senderPath,
    target: $targetPath,
    metadata: ['traceId' => 'abc-123'],
);

// Or use the convenience factory (no metadata):
$envelope = Envelope::of($myMessage, $senderPath, $targetPath);
```

### Properties

| Property | Type | Description |
|---|---|---|
| `message` | `object` | The actual message payload |
| `sender` | `ActorPath` | Path of the sending actor |
| `target` | `ActorPath` | Path of the receiving actor |
| `metadata` | `array<string, string>` | Arbitrary key-value metadata (trace IDs, timestamps, etc.) |

### Immutable modifiers

```php
$updated = $envelope->withMetadata(['traceId' => 'def-456']);
$redirected = $envelope->withSender($newSenderPath);
```

Both return a new `Envelope` -- the original is unmodified.

## Mailbox interface

The `Mailbox` interface defines the contract that runtime implementations must
fulfill. You rarely interact with it directly, but understanding it helps when
writing custom runtimes or debugging mailbox behavior.

```php
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\EnqueueResult;
use Monadial\Nexus\Core\Duration;
use Fp\Functional\Option\Option;

interface Mailbox
{
    public function enqueue(Envelope $envelope): EnqueueResult;
    public function dequeue(): Option;           // Option<Envelope>
    public function dequeueBlocking(Duration $timeout): Envelope;
    public function count(): int;
    public function isFull(): bool;
    public function isEmpty(): bool;
    public function close(): void;
}
```

- `enqueue()` is marked `#[NoDiscard]` -- you must inspect the `EnqueueResult`.
  Throws `MailboxClosedException` if the mailbox has been closed.
- `dequeue()` returns `Option::none()` if the mailbox is empty, `Option::some($envelope)` otherwise.
- `dequeueBlocking()` blocks the current fiber/coroutine until a message arrives
  or the timeout elapses. Throws `MailboxClosedException` if the mailbox is
  closed while waiting.
- `close()` permanently shuts down the mailbox. Subsequent `enqueue()` calls
  throw `MailboxClosedException`.

### EnqueueResult

The `EnqueueResult` enum reports the outcome of an `enqueue()` call:

| Value | Meaning |
|---|---|
| `EnqueueResult::Accepted` | Message was successfully added to the mailbox |
| `EnqueueResult::Dropped` | Message was discarded (DropNewest or DropOldest strategy) |
| `EnqueueResult::Backpressured` | Sender was blocked until space became available |

## Applying a mailbox configuration

Attach a mailbox configuration to an actor through `Props::withMailbox()`:

```php
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;

$behavior = Behavior::receive(
    fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
);

$props = Props::fromBehavior($behavior)->withMailbox(
    MailboxConfig::bounded(500, OverflowStrategy::DropOldest),
);

$ref = $system->spawn($props, 'bounded-actor');
```

When no mailbox configuration is specified, `Props::fromBehavior()` defaults to
`MailboxConfig::unbounded()`.
