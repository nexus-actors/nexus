---
sidebar_position: 2
title: nexus-runtime
---

# nexus-runtime

Runtime abstractions and async primitives extracted from `nexus-core`.

**Composer:** `nexus-actors/runtime`

**Namespace:** `Monadial\Nexus\Runtime\`

## What It Contains

- `Monadial\Nexus\Runtime\Async\Future`
- `Monadial\Nexus\Runtime\Async\FutureSlot`
- `Monadial\Nexus\Runtime\Async\LazyFutureSlot`
- `Monadial\Nexus\Runtime\Duration`
- `Monadial\Nexus\Runtime\Runtime\Cancellable`
- `Monadial\Nexus\Runtime\Runtime\Runtime`
- `Monadial\Nexus\Runtime\Mailbox\Mailbox`
- `Monadial\Nexus\Runtime\Mailbox\MailboxConfig`
- `Monadial\Nexus\Runtime\Mailbox\OverflowStrategy`
- `Monadial\Nexus\Runtime\Mailbox\EnqueueResult`
- `Monadial\Nexus\Runtime\Exception\MailboxException`
- `Monadial\Nexus\Runtime\Exception\MailboxClosedException`
- `Monadial\Nexus\Runtime\Exception\MailboxOverflowException`
- `Monadial\Nexus\Runtime\Exception\MailboxTimeoutException`
- `Monadial\Nexus\Runtime\Exception\InvalidMailboxConfigException`
- `Monadial\Nexus\Runtime\Exception\FutureException`
- `Monadial\Nexus\Runtime\Exception\FutureTimeoutException`

## Why It Exists

`Future` composition and runtime contracts can now be reused outside the actor
system package boundary. `nexus-core` depends on this package instead of
defining async/runtime primitives internally.

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
