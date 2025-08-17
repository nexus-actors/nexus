# ADR 0002: Immutable Behavior-Based Actor API

## Status

Accepted

## Context

Traditional actor frameworks (e.g., classic Akka) use mutable actor classes where state is stored in instance fields and mutated in message handlers. This leads to subtle bugs: handlers can accidentally share state references, and the actor's state is implicit rather than explicit.

Akka Typed introduced an alternative: immutable `Behavior` objects. Each message handler returns a new `Behavior` that defines how the next message should be processed. State transitions are explicit. The actor "becomes" a new behavior.

## Decision

Adopt the immutable behavior model:

- `Behavior<T>` is a value object. It is never mutated.
- Message handlers return the next `Behavior` (or `Behavior::same()` to keep current behavior).
- State is captured in closures or passed explicitly via `Behavior::withState()`.
- `Behavior::setup()` provides access to `ActorContext` for spawning children, scheduling, and self-reference.
- Special behaviors: `Behavior::stopped()`, `Behavior::unhandled()`, `Behavior::same()`.

Two APIs for defining actors:
1. **Functional**: `Behavior::receive(fn)` with closures — concise, good for simple actors.
2. **Class-based**: `AbstractActor` with `createBehavior()` — better for complex actors with dependencies.

## Consequences

- No mutable actor state — all state changes produce a new behavior instance.
- `Behavior::withState($state, $handler)` carries typed state through transitions.
- Stashing is explicit: `$ctx->stash()` buffers messages, `$ctx->unstashAll()` replays them.
- Props wraps behavior factories for spawning: `Props::fromBehavior()`, `Props::fromFactory()`.
- Testing is straightforward: behaviors are pure functions from (context, message) → behavior.
