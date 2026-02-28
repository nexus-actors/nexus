---
sidebar_position: 4
title: Key concepts
---

# Key concepts

This page explains the core ideas behind the actor model as implemented in
Nexus. No code — just the mental model behind the API.

## Actors

An actor is a lightweight unit of computation. Think of it as an object that:

- Has private state that no other code can read or write directly.
- Processes one message at a time, sequentially.
- Can send messages to other actors.
- Can create (spawn) child actors.
- Can change how it handles the next message.

Actors are not threads. They are much cheaper to create -- a system can run
thousands or tens of thousands of actors in a single process. The runtime
schedules actors cooperatively (with Fibers) or preemptively (with Swoole
coroutines), but each actor itself always processes one message before moving
to the next.

This single-message-at-a-time guarantee eliminates an entire class of
concurrency bugs. There are no locks, no mutexes, no race conditions on actor
state.

## Message passing

Actors communicate exclusively by sending messages. There are two patterns:

**Tell (fire-and-forget).** The sender puts a message in the recipient's mailbox
and moves on. It does not wait for a response and does not know when -- or
whether -- the message will be processed. This is the most common pattern and
the default way actors interact.

**Ask (request-reply).** The sender creates a temporary actor behind the scenes,
passes its reference in the message, and waits for a response with a timeout.
This is the pattern to use when a result is needed synchronously, but it blocks
the calling fiber until the reply arrives (or the timeout expires). Prefer tell
when possible.

Messages are plain PHP objects, typically `readonly class` instances. They carry
data, not behavior. An actor decides what to do based on the type and content of
the message it receives.

## Mailboxes

Every actor has a mailbox -- a queue where incoming messages wait until the actor
is ready to process them. The actor pulls messages from its mailbox one at a
time. If messages arrive faster than the actor can handle them, they accumulate
in the mailbox.

Mailboxes can be configured with capacity limits and overflow strategies
(drop newest, drop oldest, or backpressure). The default is an unbounded
mailbox.

## Behaviors

A behavior defines how an actor handles its current message. In Nexus, behaviors
are immutable value objects. When an actor processes a message, it returns its
next behavior:

- **Same** -- keep the current handler for the next message.
- **New behavior** -- switch to a different handler, potentially with different
  state.
- **Stopped** -- terminate the actor.
- **Unhandled** -- signal that the actor does not recognize this message type.

This approach is sometimes called "become" in other actor frameworks. The key
insight is that an actor's message handler is not fixed for its lifetime. An
actor can start in an "initializing" behavior, transition to "running" after
receiving a configuration message, and switch to "draining" when it is asked
to shut down. Each behavior handles only the messages relevant to that phase.

Stateful behaviors thread a state value through each invocation. The handler
receives the current state alongside the message and returns the next state.
The state can be any PHP value -- an integer, an array, a domain object.

## Actor hierarchy

Actors form a tree. Every actor has exactly one parent (except the root), and
can have zero or more children. Actors spawned from the actor system become
children of the `/user` guardian. When an actor spawns a child from
its own context, that child is nested under the spawning actor.

Paths look like filesystem paths:

- `/` -- the root
- `/user` -- the user guardian (parent of all application actors)
- `/user/orders` -- an actor named "orders" under the user guardian
- `/user/orders/order-123` -- a child of the "orders" actor

The hierarchy serves two purposes. First, it provides a naming and addressing
scheme for reasoning about system structure. Second, it defines supervision
boundaries.

## Supervision

Supervision is how Nexus handles failure. When an actor throws an exception
while processing a message, its parent decides what happens next. The parent
applies a supervision strategy that maps exception types to directives:

- **Restart** -- stop the failed actor and create a fresh instance with the
  original behavior. The mailbox is preserved, so pending messages are not lost.
- **Stop** -- terminate the failed actor permanently.
- **Escalate** -- the parent does not know how to handle this failure, so it
  reports the problem to its own parent.

Nexus provides built-in strategies:

- **One-for-one** -- only the failed child is acted upon. Other children
  continue running.
- **All-for-one** -- when one child fails, the directive is applied to all
  children. Useful when children are interdependent.
- **Exponential backoff** -- restarts with increasing delays between attempts.

Supervision strategies are configured per actor via its `Props`. The maximum
number of retries within a time window is configurable, along with a custom
decider function that inspects the exception and returns the appropriate
directive.

The result is a self-healing system. Transient failures (database timeouts,
network blips) trigger automatic restarts. Permanent failures escalate until
a supervisor high enough in the hierarchy can handle them -- or the system
shuts down the subtree.

## Location transparency

An `ActorRef` is a handle to an actor used to send messages. The critical
property of actor references is location transparency: the same interface works
regardless of where the actor lives.

An `ActorRef` might point to an actor in the same process or to one in a
different process or machine. The application code does not change between
these cases.

This is what makes the actor model suitable for distributed systems. The
application is designed as a set of actors exchanging messages. Deployment
topology — single process, multiple processes, multiple machines — is a
configuration concern, not a code concern.

## Runtimes

Nexus separates the actor model abstractions (in `nexus-core`) from the
execution engine. A runtime provides:

- Scheduling: running actor message loops concurrently.
- Mailbox implementation: the underlying queue mechanism.
- Timer scheduling: delayed and repeated callbacks.

The **Fiber runtime** uses PHP's native fiber API for cooperative multitasking
within a single thread. It is the simplest runtime, requires no extensions, and
is suitable for development, testing, and moderate workloads.

The **Swoole runtime** uses Swoole coroutines and channels for concurrent
execution with I/O multiplexing. It supports true parallel processing and is
designed for production workloads with high throughput requirements.

Both runtimes implement the same `Runtime` interface. The runtime is selected
at startup; the rest of the application code remains unchanged.

## Summary

| Concept | What it means |
|---|---|
| Actor | Lightweight unit with private state, processing one message at a time |
| Message | Immutable PHP object sent between actors |
| Mailbox | Per-actor queue buffering incoming messages |
| Behavior | Immutable handler that defines how the next message is processed |
| Hierarchy | Tree structure of parent-child actor relationships |
| Supervision | Parent-controlled failure handling via strategies and directives |
| Location transparency | Same ActorRef interface whether the actor is local or remote |
| Runtime | Pluggable execution engine (Fiber or Swoole) |
