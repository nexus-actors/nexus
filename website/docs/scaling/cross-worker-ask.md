---
title: Cross-worker ask
related:
  - scaling/overview
  - scaling/choosing-thread-count
  - core-concepts/ask-pattern
  - reference/classes/worker-node
---

# Cross-worker ask

When one actor sends an `ask()` to a `WorkerActorRef` on a different thread, the request and response travel through the `WorkerTransport` — a Swoole `Thread\Queue`. This page covers the latency profile, the protocol, and the differences from a same-worker ask.

## How it works

`WorkerActorRef::ask()` builds a `WorkerAskRequest` envelope and sends it to the target worker's `Thread\Queue`. The target worker's `WorkerNode` receives the request, delivers it to the local actor, and sends a `WorkerAskReply` back to the originating worker's queue. The originating worker's `WorkerNode` resolves the `FutureSlot` and unblocks the calling fiber.

The object is not serialized — Swoole's `Thread\Queue` transfers PHP objects between threads by copying their serialized representation internally. There is no explicit user-facing serializer call.

## Latency comparison

| Ask type | Path | Approximate overhead |
|---|---|---|
| Same-worker ask | Direct mailbox enqueue + fiber suspend | < 0.1 ms |
| Cross-worker ask | Thread\Queue send + remote mailbox + Thread\Queue reply + fiber resume | 0.5–2 ms (depends on queue depth and Swoole polling interval) |

The cross-worker overhead comes from the adaptive-poll loop in `ThreadQueueTransport`: it backs off from 0 µs → 100 µs → 1 ms → 10 ms between polls when the queue is idle. Under load, the first message typically lands in the 0–100 µs window.

## Usage

`WorkerActorRef` is obtained from `WorkerNode` by looking up an actor that lives on a different thread. The API is identical to a same-worker ask:

<!-- verify:skip: requires a running worker pool with Swoole threads -->
```php title="src/Handlers/CheckoutHandler.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerActorRef;

/** @var WorkerActorRef<InventoryCommand> $inventoryRef */
$inventoryRef = $workerNode->ref('inventory');

// ask() returns a Future — await it inside a Swoole coroutine
$result = $inventoryRef->ask(
    new ReserveItems($orderId, $items),
    Duration::seconds(5),
)->await();
```

## Timeout and retry behaviour

`WorkerNode` retries the ask request up to 3 times with 50 ms between retries if the target worker does not acknowledge (`WorkerAskAck`) within the first attempt window. After 3 failed attempts, the `Future` is failed with `AskTimeoutException`.

The `Duration` passed to `ask()` is the total client-side timeout. Set it generously enough to absorb retries: a value below 150 ms risks spurious timeouts when the target worker is briefly busy.

## Serialization in V1

In V1 (thread-mode only), objects travel across threads via Swoole's `Thread\Queue`, which performs an internal serialize/unserialize. This means:

- Object identity is not preserved — a reference sent to another thread is a deep copy on arrival.
- Non-serializable resources (database connections, file handles) must not be included in messages.
- The `#[MessageType]` attribute is not required for cross-worker messages (unlike cluster-mode remote transport where explicit serialization is configured).

:::caution Resource handles in messages
Do not include PDO connections, Swoole coroutine channels, or other resource handles in cross-worker messages. Swoole's internal thread-queue serialization will fail or produce corrupt values.
:::

## When cross-worker ask adds up

A request that fans out to N workers and awaits all responses incurs N × cross-worker latency. For fan-out to all workers, prefer `tell()` with a coordinator actor collecting replies, rather than N sequential `ask()` calls.

## See also

- [Ask pattern](../core-concepts/ask-pattern.md) — same-worker ask mechanics and `Future`.
- [Scaling overview](./overview.md) — consistent hash ring and worker topology.
- [Choosing thread count](./choosing-thread-count.md) — sizing the pool to handle cross-worker overhead.
