---
sidebar_position: 1
title: Runtime Overview
runtimes: ['fiber', 'swoole', 'step']
related:
  - runtimes/fiber
  - runtimes/swoole
  - runtimes/step
  - runtimes/bootstrap
---

# Runtime Overview

The `Runtime` interface decouples actor code from the underlying concurrency mechanism — scheduling, mailbox creation, and fiber/coroutine management all flow through this single contract, making actor behaviors portable across runtimes.

## Choosing a runtime

- **Use `FiberRuntime` when** you are running unit tests, development servers, or applications on standard PHP without Swoole.
- **Use `SwooleRuntime` when** you need true async I/O, WebSocket connections, or production throughput beyond what fibers provide.
- **Use `StepRuntime` when** you are writing deterministic tests that need message-by-message control over actor execution order.

## The design

import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

Every runtime implementation satisfies the same `Runtime` interface. Actor code — behaviors, props definitions, supervision strategies — never references a specific runtime class. The runtime is injected once, at the composition root:

<Tabs groupId="runtime">
  <TabItem value="fiber" label="Fiber">
    ```php title="src/bootstrap.php"
    use Monadial\Nexus\Core\Actor\ActorSystem;
    use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

    $system = ActorSystem::create('my-system', new FiberRuntime());
    ```
  </TabItem>
  <TabItem value="swoole" label="Swoole">
    ```php title="src/bootstrap.php"
    use Monadial\Nexus\Core\Actor\ActorSystem;
    use Monadial\Nexus\Runtime\Swoole\SwooleConfig;
    use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

    $system = ActorSystem::create('my-system', new SwooleRuntime(new SwooleConfig()));
    ```
  </TabItem>
</Tabs>

Selecting "Swoole" here persists that choice across every `groupId="runtime"` tab block site-wide — you won't have to re-select your runtime on each page.

The `Runtime` interface exposes eleven methods organized into three groups:

**Lifecycle** — `run()` starts the event loop (blocking until shutdown), `shutdown(Duration $timeout)` signals a graceful stop, `isRunning()` reports current state.

**Concurrency** — `spawn(callable $actorLoop)` registers a new concurrent task (fiber or coroutine) and returns an ID string, `yield()` cooperatively yields, `sleep(Duration)` suspends the current task.

**Scheduling** — `createMailbox(MailboxConfig)` creates a runtime-specific mailbox, `createFutureSlot()` creates a slot for `Future` resolution, `scheduleOnce(Duration, callable)` fires a callback once after a delay, `scheduleRepeatedly(Duration, Duration, callable)` fires on a fixed interval. Both scheduling methods return a `Cancellable`.

## Three implementations

| Runtime | Extension required | Concurrency model | Primary use |
|---|---|---|---|
| `FiberRuntime` | None | PHP 8.1+ native fibers | Development, CI, simple services |
| `SwooleRuntime` | Swoole 6.2.1+ | Swoole coroutines | Production, high concurrency |
| `StepRuntime` | None | Manual stepping (fibers internally) | Deterministic testing |

## Tradeoffs

The Fiber runtime requires no extensions and gives you a familiar debugging experience, but all fibers run in a single thread. Blocking I/O anywhere in a handler stalls the entire event loop.

The Swoole runtime provides true async I/O through coroutine hooking — blocking PHP I/O calls (database queries, HTTP requests, file reads) are transparently converted to non-blocking operations. The cost is a required extension and more complex debugging.

The Step runtime sacrifices real-time execution for complete determinism. Time never advances unless you call `advanceTime()`. Messages are never processed unless you call `step()`. This makes it unsuitable for production but invaluable for testing actor logic in isolation.

## When to reach for it

- Running a local development server or a CLI tool without Swoole installed: Fiber runtime.
- Writing a test that needs to assert state after exactly the third message an actor receives: Step runtime.
- Deploying a service with WebSocket connections, high-throughput I/O, or multi-worker scaling: Swoole runtime.
- Sharing `Duration` and `Future` abstractions across packages without committing to the actor model: use the runtime package standalone, without an `ActorSystem`.

## See also

- [Fiber Runtime](./fiber.md) — cooperative scheduling, mailbox suspension, limitations
- [Swoole Runtime](./swoole.md) — coroutine hooking, configuration, graceful shutdown
- [Step Runtime](./step.md) — `step()`, `drain()`, `advanceTime()`, testing patterns
- [Bootstrap](./bootstrap.md) — install and wire up any runtime in under a minute
