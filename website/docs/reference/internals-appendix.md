---
title: Internal Classes
sidebar_position: 99
---

# Internal classes

These classes are part of Nexus's runtime implementation but are **not part of the
public API surface**. They are tagged `@internal` in source so they do not appear in
the public sidebar of [api.nexusactors.com](https://api.nexusactors.com).

Their HTML pages still exist for deep-links; this page lists them so you know they
are there and why they are hidden.

Do not depend on these classes directly. If a use case calls for it,
[file an issue](https://github.com/nexus-actors/nexus/issues) so we can discuss
promoting the relevant API to public.

## Behavior wrappers (11)

These classes are the concrete sentinels and decorators returned by the public
`Behavior` factory methods. Users call the factories — `Behavior::same()`,
`Behavior::receive()`, etc. — and hold them as opaque `Behavior<T>` values. The
concrete wrapper type is an implementation detail of `ActorCell`.

| Class | Why internal |
|---|---|
| `EmptyBehavior` | Sentinel returned internally before the first behavior is set. Never constructed by users. |
| `SameBehavior` | Sentinel returned by `Behavior::same()`. Users call the factory, never construct directly. |
| `StoppedBehavior` | Sentinel returned by `Behavior::stopped()`. Users call the factory, never construct directly. |
| `UnhandledBehavior` | Sentinel returned by `Behavior::unhandled()`. Routes message to dead letters — implementation detail. |
| `SupervisedBehavior` | Wraps a behavior under supervision restart. Created by `ActorCell` during fault recovery. |
| `SetupBehavior` | Lazy-init wrapper returned by `Behavior::setup()`. Resolved to a concrete behavior on first message. |
| `ReceiveBehavior` | Closure-based behavior returned by `Behavior::receive()`. Holds the handler closure internally. |
| `WithStashBehavior` | Behavior decorator that activates the stash buffer. Used by `ActorCell` internals. |
| `WithStateBehavior` | Stateful closure behavior returned by `Behavior::withState()`. Holds state and handler. |
| `WithTimersBehavior` | Behavior decorator that attaches a `TimerScheduler`. Managed by `ActorCell`. |
| `UnstashAllBehavior` | Sentinel that triggers draining the stash buffer. Internal to stash machinery. |

## Engine / Runtime (8)

Implementation classes behind public interfaces and factory methods. Users interact
with their public interfaces (`ActorRef<T>`, `ActorPath`, `StashBuffer`, etc.) and
never construct these directly.

| Class | Why internal |
|---|---|
| `ActorCell` | Runtime container for one actor's lifecycle and message loop. Implementation detail of `ActorSystem::spawn()`. |
| `DefaultStashBuffer` | Default stash buffer implementation. The `StashBuffer` interface is public; this impl is an internal detail. |
| `DefaultTimerScheduler` | Default timer scheduler implementation. Users access timers via `ActorContext::scheduleOnce()`. |
| `LocalActorRef` | Concrete `ActorRef` for in-process actors. Users hold the `ActorRef<T>` interface, not this class. |
| `DeadLetterRef` | Null-object `ActorRef` for undeliverable messages. Users access via `ActorSystem::deadLetters()`. |
| `FutureRef` | Temporary `ActorRef` created for `ask()` replies. Created and destroyed per request. |
| `TaskContext` | Internal context used during `ask()` future resolution. Not user-facing. |
| `ActorState` | Enum of internal lifecycle states (`New`, `Starting`, `Running`, etc.). A private detail of `ActorCell`. |

## Context / Support (4)

Helper types that are shared internally between subsystems but not surfaced to
application code.

| Class | Why internal |
|---|---|
| `LocalActorPath` | Concrete `ActorPath` for in-process actors. Implementation detail of path creation. |
| `RemoteActorPath` | Concrete `ActorPath` for remote actors. Implementation detail of cluster path parsing. |
| `ActorPathContract` | Internal interface shared between `LocalActorPath` and `RemoteActorPath`. Not a public API contract. |
| `NullDispatcher` | No-op PSR-14 `EventDispatcherInterface` used when no dispatcher is provided. Internal default. |

## System messages (6)

Internal control messages sent between `ActorCell` instances by the framework. These
are never sent by user code directly — users send domain messages via `tell()` or
`ask()`, and the framework manages system messages automatically.

| Class | Why internal |
|---|---|
| `Watch` | Sent internally to register a death-watch subscription between actors. |
| `Unwatch` | Sent internally to cancel a death-watch subscription. |
| `Suspend` | Sent internally to pause an actor's message processing (e.g. during supervision). |
| `Resume` | Sent internally to resume a suspended actor after supervision completes. |
| `Kill` | Sent internally for a forced stop that bypasses graceful shutdown. |
| `SystemMessage` | Marker interface for all of the above internal system messages. |
