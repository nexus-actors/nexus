---
title: MailboxConfig
sidebar_position: 14
related:
  - core-concepts/mailboxes
  - reference/classes/props
  - reference/classes/runtime
  - reference/classes/envelope
---

# MailboxConfig

Immutable value object that configures a mailbox's capacity and overflow behaviour.

## What it does

`MailboxConfig` controls how messages are buffered before an actor processes them. Every actor has exactly one mailbox; by default it is unbounded, accepting messages as fast as senders produce them. When back-pressure or bounded-queue semantics are required — for example, to protect a slow downstream service from being overwhelmed — you switch to a bounded mailbox via `MailboxConfig::bounded()`.

A bounded mailbox enforces a hard capacity limit. When that limit is reached the configured `OverflowStrategy` decides what happens next:

- **`DropNewest`** — silently discard the incoming message.
- **`DropOldest`** — evict the oldest message in the queue to make room.
- **`Backpressure`** — suspend the sending fiber/coroutine until space becomes available.
- **`ThrowException`** — throw `MailboxOverflowException` at the send site (the default for bounded mailboxes, making overflows visible).

`MailboxConfig` is a `readonly` class — every mutating method returns a new instance, keeping the original unchanged.

## Example

```php title="src/OrderProcessorActor.php"
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;
use Monadial\Nexus\Core\Actor\Props;

// Default: unbounded mailbox (no configuration needed)
$props = Props::fromBehavior($behavior);

// Bounded mailbox — drop oldest messages when full
$props = Props::fromBehavior($behavior)
    ->withMailbox(
        MailboxConfig::bounded(1_000, OverflowStrategy::DropOldest),
    );

// Bounded mailbox with back-pressure — suspend sender until space is free
$props = Props::fromBehavior($behavior)
    ->withMailbox(
        MailboxConfig::bounded(500, OverflowStrategy::Backpressure),
    );

// Start unbounded, then tighten capacity later (returns new instance)
$base    = MailboxConfig::unbounded();
$tighter = $base->withCapacity(256)->withStrategy(OverflowStrategy::DropNewest);
```

## Key methods

- `MailboxConfig::bounded(int $capacity, OverflowStrategy $strategy = OverflowStrategy::ThrowException): self` — create a bounded mailbox with the given capacity and overflow strategy.
- `MailboxConfig::unbounded(): self` — create an unbounded mailbox (default when no `MailboxConfig` is provided to `Props`).
- `->withCapacity(int $capacity): self` — return a new config with a different capacity; the overflow strategy is unchanged.
- `->withStrategy(OverflowStrategy $strategy): self` — return a new config with a different overflow strategy; the capacity is unchanged.

### `OverflowStrategy` enum values

| Value | Behaviour when capacity is reached |
|---|---|
| `DropNewest` | Silently discard the incoming message |
| `DropOldest` | Evict the oldest queued message to make room |
| `Backpressure` | Suspend the sending fiber/coroutine until space is available |
| `ThrowException` | Throw `MailboxOverflowException` at the call site |

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Runtime-Mailbox-MailboxConfig.html)

## See also

- [Mailboxes concept](../../core-concepts/mailboxes) — how mailboxes work, overflow strategies, and back-pressure patterns
- [Props](props) — `Props::withMailbox()` is how `MailboxConfig` is attached to an actor definition
- [Runtime](runtime) — `Runtime::createMailbox()` allocates the mailbox backed by this config
- [Envelope](envelope) — the immutable wrapper placed in the mailbox for each message
