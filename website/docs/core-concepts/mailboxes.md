---
sidebar_position: 5
title: Mailboxes
---

# Mailboxes

Every actor has a mailbox -- a queue that buffers incoming messages until the actor
is ready to process them. Nexus provides control over mailbox capacity and
overflow behavior through `MailboxConfig`, and wraps every message in an
`Envelope` that carries sender, target, metadata, and distributed tracing
identifiers alongside the payload.

## MailboxConfig

`MailboxConfig` is a `final readonly class` with a private constructor. Instances
are created through named constructors.

### Unbounded mailbox

The default. No capacity limit. The mailbox grows as needed.

```php
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;

$config = MailboxConfig::unbounded();
```

Internally this sets capacity to `PHP_INT_MAX` and marks the mailbox as unbounded.

### Bounded mailbox

A fixed-capacity mailbox with a configurable overflow strategy.

```php
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

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

Strategy selection depends on the actor's requirements:

- **DropNewest** -- acceptable when latest data supersedes older data (sensor
  readings, status updates).
- **DropOldest** -- acceptable when the most recent messages must be processed
  rather than older ones.
- **Backpressure** -- the sender slows down to match the consumer's pace.
  Prevents message loss but may stall upstream actors.
- **ThrowException** -- fail fast. Useful during development or when overflow
  indicates a design problem.

## Envelope

`Envelope` is a `final readonly class` that wraps every message with routing
information, metadata, and distributed tracing identifiers.

```php
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Actor\ActorPath;

// Root envelope -- all three tracing IDs are set to the same fresh value:
$envelope = Envelope::of($myMessage, $senderPath, $targetPath);
```

### Properties

| Property | Type | Description |
|---|---|---|
| `message` | `object` | The actual message payload |
| `sender` | `ActorPath` | Path of the sending actor |
| `target` | `ActorPath` | Path of the receiving actor |
| `requestId` | `string` (ULID) | Unique identifier for this specific message delivery |
| `correlationId` | `string` (ULID) | Links all messages that belong to the same logical operation |
| `causationId` | `string` (ULID) | Points to the `requestId` of the message that caused this one |
| `metadata` | `array<string, string>` | Arbitrary key-value metadata |

### Tracing identifiers

Every `Envelope` carries three ULID string identifiers for distributed tracing:

**`Envelope::of(object $message, ActorPath $sender, ActorPath $target)`** -- creates a
root envelope where all three IDs (`requestId`, `correlationId`, `causationId`) are set
to the same fresh ULID. Use this when starting a new operation with no prior context.

The intended child-envelope pattern assigns a fresh `requestId`, inherits the parent's
`correlationId`, and sets `causationId` to the parent's `requestId`. This links cause
and effect across the message chain.

`ActorContext::tell()` and `ActorContext::reply()` propagate tracing identifiers
automatically. Calling `$ref->tell()` directly on an `ActorRef` creates a root envelope
and breaks the trace chain.

### Immutable modifiers

```php
$updated = $envelope->withMetadata(['key' => 'value']);
$redirected = $envelope->withSender($newSenderPath);
$reidentified = $envelope->withRequestId($newId);
$reidentified = $envelope->withCorrelationId($correlationId);
$reidentified = $envelope->withCausationId($causationId);
```

All modifiers return a new `Envelope` -- the original is unmodified.

## Mailbox interface

The `Mailbox` interface defines the contract that runtime implementations must
fulfill. Direct interaction is rare, but understanding it helps when writing
custom runtimes or debugging mailbox behavior.

```php
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Duration;
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

- `enqueue()` is marked `#[NoDiscard]` -- the `EnqueueResult` must be inspected.
  Throws `MailboxClosedException` if the mailbox has been closed.
- `dequeue()` returns `null` if the mailbox is empty, or an `Envelope` otherwise.
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
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

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
