---
sidebar_position: 2
title: Internals
related:
  - architecture/design-philosophy
  - core-concepts/actors
  - core-concepts/lifecycle
  - core-concepts/mailboxes
---

# Internals

`ActorCell` is the internal engine that processes messages, manages behavior state, and enforces the actor lifecycle. This page describes how messages flow through the system and how the pieces fit together.

## ActorCell

`ActorCell<T>` implements `ActorContext<T>` and manages:

- **Behavior** — The current `Behavior<T>` that defines how messages are handled.
- **State machine** — Transitions through `ActorState` (see below).
- **Children map** — An array of child actor references keyed by name.
- **Stash buffer** — A list of `Envelope` objects buffered for later processing.
- **Supervision** — A `SupervisionStrategy` that determines how child failures are handled.
- **Watchers** — A map of actors watching this actor for termination.
- **Current envelope** — The envelope being processed, used by `stash()` and `sender()`.

Each `ActorCell` is created with a `Behavior`, an `ActorPath`, a `Mailbox`, a `Runtime`, an optional parent reference, a `SupervisionStrategy`, a `ClockInterface`, a `LoggerInterface`, and a `DeadLetterRef`.

## Message processing

### Behavior application

After a handler returns a `Behavior<T>`, `applyBehavior()` determines the next step:

- `Behavior::same()` — No change. The current behavior is retained.
- `Behavior::stopped()` — The actor initiates shutdown via `initiateStop()`.
- `Behavior::unhandled()` — The message is forwarded to dead letters.
- Any other behavior — The current behavior is swapped to the new one. If the new behavior is `WithState`, its initial state is extracted.

For stateful behaviors, `applyStatefulBehavior()` handles `BehaviorWithState` results:

- `BehaviorWithState::same()` — No change to behavior or state.
- `BehaviorWithState::next($state)` — Keep the same behavior, update state.
- `BehaviorWithState::stopped()` — Initiate shutdown.
- `BehaviorWithState::withBehavior($behavior, $state)` — Swap both behavior and state.

## State machine

Each actor progresses through a defined set of states:

| From | To |
|---|---|
| `New` | `Starting` |
| `Starting` | `Running` |
| `Running` | `Suspended`, `Stopping` |
| `Suspended` | `Running`, `Stopping` |
| `Stopping` | `Stopped` |
| `Stopped` | (terminal) |

The `ActorState` enum enforces these transitions via `canTransitionTo()`. Attempting an invalid transition throws `InvalidActorStateTransition`.

### Lifecycle during transitions

1. **New → Starting** — `ActorCell::start()` is called. If the behavior is `Setup`, the factory closure is invoked with the `ActorContext` to produce the initial behavior. If `WithState`, the initial state is extracted.
2. **Starting → Running** — The `PreStart` signal is delivered to the signal handler (if one is registered).
3. **Running → Stopping** — `initiateStop()` is called. All children receive a `PoisonPill`. The `PostStop` signal is delivered. The mailbox is closed.
4. **Stopping → Stopped** — Terminal state. The actor is no longer alive.

## Message loop

Each actor gets its own fiber (Fiber runtime) or coroutine (Swoole runtime) that runs a message processing loop:

```php title="packages/nexus-core/src/Actor/ActorCell.php"
while ($cell->isAlive()) {
    try {
        $envelope = $mailbox->dequeueBlocking(Duration::seconds(1));
        $cell->processMessage($envelope);
    } catch (MailboxClosedException) {
        break;
    }
}
```

The loop blocks on `dequeueBlocking()` — in the Fiber runtime this suspends the fiber, in the Swoole runtime this suspends the coroutine, and in the Step runtime the fiber always suspends to give the test control over when each message is processed. When the mailbox is closed during actor shutdown, `MailboxClosedException` breaks the loop.

This loop is spawned by `ActorSystem` (for top-level actors) and by `ActorCell` (for child actors) via `$runtime->spawn()`.

## Dead letters

Messages that cannot be delivered are captured by the `DeadLetterRef`:

- Messages sent to a stopped actor's `LocalActorRef` are silently dropped. The mailbox is closed, so `enqueue()` throws `MailboxClosedException`, which `LocalActorRef::tell()` catches and discards.
- Messages that a behavior returns `Behavior::unhandled()` for are forwarded to the dead letter reference.
- Messages sent to actors with empty behaviors (no handler) are forwarded to dead letters.

The `DeadLetterRef` lives at the path `/system/deadLetters` and captures all received messages in an internal list accessible via `captured()`. This is useful for debugging and testing.

`DeadLetterRef::ask()` immediately throws `AskTimeoutException`, and `isAlive()` always returns `false`.

## Tradeoffs

`ActorCell` is the heaviest internal object — it holds all per-actor state. This is intentional: everything an actor needs to process a message is collocated in one place, making the processing loop simple and predictable.

The cost is memory: one `ActorCell` per live actor, regardless of how many messages that actor processes. For systems with large numbers of idle actors, idle passivation (via `ReceiveTimeout`) reduces this cost by stopping actors that have not received a message within a configurable window.

## See also

- [Design philosophy](./design-philosophy.md) — the principles that shaped these internals.
- [Lifecycle](../core-concepts/lifecycle.md) — the `PreStart` / `PostStop` signals and what triggers them.
- [Mailboxes](../core-concepts/mailboxes.md) — how the mailbox interface works and what overflow strategies are available.
