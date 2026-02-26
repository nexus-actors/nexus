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

## Bootstrap

See [Bootstrap Runtime](../runtimes/bootstrap.md) for actor-system and
standalone setup flows.
