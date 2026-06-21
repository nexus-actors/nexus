---
sidebar_position: 4
title: Key Concepts
related:
  - core-concepts/actors
  - core-concepts/behaviors
  - core-concepts/supervision
  - runtimes/overview
---

# Key Concepts

This page explains the core ideas behind the actor model as implemented in Nexus. No code — the goal is the mental model you need before working with the API.

## Actors

An actor is a lightweight unit of computation with private state that no other code can read or write directly. Each actor:

- Processes one message at a time, sequentially.
- Sends messages to other actors.
- Spawns child actors.
- Changes how it handles the next message by returning a new behavior.

Actors are not threads. You can run thousands of actors in a single process. The runtime schedules actors cooperatively (with fibers) or preemptively (with Swoole coroutines), but each actor always finishes processing one message before moving to the next.

This single-message-at-a-time guarantee eliminates an entire class of concurrency bugs. There are no locks, no mutexes, no race conditions on actor state.

## Message passing

Actors communicate exclusively by sending messages. Two patterns exist:

**Tell (fire-and-forget).** The sender puts a message in the recipient's mailbox and moves on immediately — no waiting for a response, no acknowledgement. This is the most common pattern.

**Ask (request-reply).** The sender calls `ask()`, which delivers the message and returns a `Future`. Calling `->await()` on the `Future` suspends the current fiber until the actor calls `$ctx->reply()` or the timeout expires. Use ask when you need a value back; prefer tell everywhere else.

Messages are plain PHP objects, typically `readonly class` instances. They carry data, not behavior. An actor decides what to do based on the type and content of the message it receives.

## Mailboxes

Every actor has a mailbox — a queue where incoming messages wait until the actor is ready to process them. The actor pulls messages from its mailbox one at a time. If messages arrive faster than the actor can process them, they accumulate in the mailbox.

Mailboxes can be configured with capacity limits and overflow strategies (drop newest, drop oldest, or backpressure). The default is an unbounded mailbox.

## Behaviors

A behavior defines how an actor handles its current message. In Nexus, behaviors are immutable value objects. When an actor processes a message, it returns its next behavior:

- **`Behavior::same()`** — keep the current handler for the next message.
- **`Behavior::receive(fn)`** — switch to a different stateless handler.
- **`Behavior::stopped()`** — terminate the actor.
- **`Behavior::unhandled()`** — signal that the actor does not recognize this message type.

This approach is sometimes called "become" in other actor frameworks. An actor can start in an "initializing" behavior, transition to "running" after receiving a configuration message, and switch to "draining" when shutdown begins. Each behavior handles only the messages relevant to that phase.

Stateful behaviors thread a state value through each invocation. The handler receives the current state alongside the message and returns the next state via `BehaviorWithState`. The state can be any PHP value — an integer, an array, a domain object.

## Actor hierarchy

Actors form a tree. Every actor has exactly one parent (except the root), and can have zero or more children. When you spawn an actor from the actor system, it becomes a child of the `/user` guardian. When an actor spawns a child from its own actor context, that child is nested under the spawning actor.

Paths look like filesystem paths:

- `/` — the root
- `/user` — the user guardian (parent of all application actors)
- `/user/orders` — an actor named `orders` under the user guardian
- `/user/orders/order-123` — a child of the `orders` actor

The hierarchy serves two purposes: it provides a naming and addressing scheme, and it defines supervision boundaries.

## Supervision

Supervision is how Nexus handles failure. When an actor throws an exception while processing a message, its parent decides what happens next. The parent applies a supervision strategy that maps exception types to directives:

- **Restart** — stop the failed actor and create a fresh instance with the original behavior. The mailbox is preserved, so pending messages are not lost.
- **Stop** — terminate the failed actor permanently.
- **Escalate** — pass the failure to the parent's own parent.
- **Resume** — continue processing the next message as if nothing happened.

Nexus provides built-in strategies:

- **One-for-one** — only the failed child is acted upon. Other children continue running.
- **All-for-one** — when one child fails, the directive applies to all children. Useful when children are interdependent.
- **Exponential backoff** — restarts with increasing delays between attempts.

You configure supervision strategies per actor via `Props`. A custom decider function inspects the exception and returns the appropriate directive.

## Location transparency

An `ActorRef` is a handle to an actor. The critical property is location transparency: the same `tell()` and `ask()` interface works regardless of where the actor lives — in the same process, on a different thread in a worker pool, or on a different machine in a cluster.

Your code does not change based on deployment topology. The routing is a configuration concern, not a code concern.

## Runtimes

Nexus separates the actor model abstractions (in `nexus-core`) from the execution engine. A `Runtime` provides scheduling, mailbox implementation, and timer scheduling.

The **Fiber runtime** uses PHP's native fiber API for cooperative multitasking within a single thread. It requires no extensions and is suitable for development, testing, and moderate workloads.

The **Swoole runtime** uses Swoole coroutines and channels for concurrent execution with I/O multiplexing. It is designed for production workloads with high throughput requirements.

Both runtimes implement the same `Runtime` interface. You choose the runtime at startup; the rest of your application code remains unchanged.

## Concept summary

| Concept | What it means |
|---|---|
| Actor | Lightweight unit with private state, processing one message at a time |
| Message | Immutable PHP object sent between actors |
| Mailbox | Per-actor queue buffering incoming messages |
| Behavior | Immutable handler that defines how the next message is processed |
| Hierarchy | Tree structure of parent-child actor relationships |
| Supervision | Parent-controlled failure handling via strategies and directives |
| Location transparency | Same `ActorRef` interface whether the actor is local or remote |
| Runtime | Pluggable execution engine (`FiberRuntime` or `SwooleRuntime`) |

## Next steps

- [Quick Start](./quick-start.md) — see these concepts in code.
- [Actors in depth](../core-concepts/actors.md) — the full actor lifecycle and message-processing contract.
- [Supervision](../core-concepts/supervision.md) — configure restart strategies and deciders.
- [Runtimes](../runtimes/overview.md) — choose the right runtime for your workload.
