---
sidebar_position: 2
title: nexus-runtime
---

# nexus-runtime

Runtime abstractions and async primitives.

**Composer:** `nexus-actors/runtime`

**Namespace:** `Monadial\Nexus\Runtime\`

## Async namespace

`Monadial\Nexus\Runtime\Async\`

| Class / Interface | Description |
|---|---|
| `Future<T>` | Async result handle. Methods: `await()`, `map(Closure)`, `flatMap(Closure)`, `isResolved()`. |
| `FutureSlot<T>` | Runtime-backed resolver for `Future`. Methods: `resolve(object)`, `fail(FutureException)`, `await()`, `isResolved()`. |
| `LazyFutureSlot<T>` | Internal lazy `FutureSlot` used by combinators (`map`, `flatMap`). |

## Runtime namespace

`Monadial\Nexus\Runtime\Runtime\`

| Class / Interface | Description |
|---|---|
| `Runtime` | Runtime contract used by runtimes like Fiber, Swoole, and Step. |
| `Cancellable` | Cancellation handle for scheduled tasks (`cancel()`, `isCancelled()`). |

## Mailbox namespace

`Monadial\Nexus\Runtime\Mailbox\`

| Class / Interface | Description |
|---|---|
| `Mailbox<T>` | Generic mailbox contract used by runtime implementations. |
| `MailboxConfig` | Immutable mailbox configuration (`bounded()`, `unbounded()`, `withCapacity()`, `withStrategy()`). |
| `OverflowStrategy` | Overflow behavior enum (`DropNewest`, `DropOldest`, `Backpressure`, `ThrowException`). |
| `EnqueueResult` | Enqueue result enum (`Accepted`, `Dropped`, `Backpressured`). |

## Exception namespace

`Monadial\Nexus\Runtime\Exception\`

| Class / Interface | Description |
|---|---|
| `FutureException` | Base marker interface for future failures. |
| `FutureTimeoutException` | Marker interface for timeout-style future failures. |
| `MailboxException` | Base mailbox exception. |
| `MailboxClosedException` | Mailbox operation on closed mailbox. |
| `MailboxOverflowException` | Mailbox overflow with throw strategy. |
| `MailboxTimeoutException` | Mailbox operation timed out. |
| `InvalidMailboxConfigException` | Invalid mailbox configuration. |

## Duration

`Monadial\Nexus\Runtime\Duration`

Immutable nanosecond-precision duration value object used by all runtime APIs.

## When To Use `nexus-runtime` Only

Use `nexus-runtime` without `nexus-core` when you need:

- async result composition (`Future`, `map`, `flatMap`, `await`) in non-actor code
- runtime-neutral scheduling contracts for adapters/infrastructure code
- deterministic orchestration in tests (for example with `nexus-runtime-step`)
- shared timeout/cancellation primitives (`Duration`, `Cancellable`) in libraries

## Why It Is Useful

- keeps actor-free modules lightweight and decoupled from actor APIs
- lets infrastructure code depend on stable runtime contracts only
- improves testability by using runtime implementations directly
- avoids forcing full actor-system adoption when only async primitives are needed

## Bootstrap

See [Bootstrap Runtime](../runtimes/bootstrap.md) for actor-system and
standalone setup flows.
