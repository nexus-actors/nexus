---
title: WorkerNode
sidebar_position: 24
related:
  - scaling/overview
  - reference/classes/actor-system
---

# WorkerNode

Per-worker coordinator in a Swoole thread-based worker pool; routes actor messages
via a consistent hash ring without serialization.

## What it does

In a `WorkerPoolApp` deployment, Nexus creates one Swoole thread per worker. Each
thread owns exactly one `WorkerNode`. The node holds the thread-local `ActorSystem`,
consults the `ConsistentHashRing` to decide which worker owns a given actor name, and
routes cross-worker messages via the `WorkerTransport` (backed by Swoole's
`Thread\Queue`).

Swoole's `Thread\Queue` copies objects between threads internally — no serializer is
involved. This means cross-worker `ask()` / `tell()` has the same ergonomics as
local actor communication.

Typical usage is indirect: implement `WorkerStartHandler` and call `$node->spawn()`
inside `onWorkerStart()`. The framework routes messages automatically based on the
hash ring assignment:

- If the hash ring assigns the actor name to **this** worker, `spawn()` creates a
  local actor in the thread's `ActorSystem` and returns a `LocalActorRef`.
- If the hash ring assigns the actor name to a **different** worker, `spawn()` returns
  a `WorkerActorRef` that routes messages via the transport.

Call `$node->start()` once all actors are registered. This installs the inbound
transport listener that delivers cross-worker envelopes to local actors' mailboxes.

## Example

```php title="src/MyWorkerStart.php"
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;

final class MyWorkerStart implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void
    {
        // Both calls respect the hash ring — actors land on the right worker
        $node->spawn(Props::fromBehavior($orderBehavior), 'orders');
        $node->spawn(Props::fromBehavior($paymentBehavior), 'payments');

        // Start the inbound transport listener
        $node->start();
    }
}

// Boot the pool
MyWorkerPoolApp::run(WorkerPoolConfig::withThreads(4));
```

To send a message to an actor from outside the worker start handler, use
`actorFor()` to resolve a ref by path:

```php
$ref = $node->actorFor('/user/orders');
$ref?->tell(new PlaceOrder($orderId));
```

## Key methods

- `spawn(Props $props, string $name): ActorRef` — spawn (or proxy) an actor,
  routing to the hash-ring-assigned worker.
- `actorFor(string $path): ?ActorRef` — look up an actor by path; returns a local
  or remote ref, or `null` if unknown.
- `start(): void` — begin listening for inbound transport envelopes. Call after all
  actors are spawned.
- `workerId(): int` — this node's numeric worker ID.
- `system(): ActorSystem` — the thread-local `ActorSystem` backing this node.
- `askRemote(ActorPath $targetPath, int $targetWorker, object $message, Duration $timeout): Future`
  — low-level cross-worker ask with retry and timeout. Called internally by
  `WorkerActorRef::ask()`.

## Full API reference

[Full class and method signatures](https://api.nexusactors.com/classes/Monadial-Nexus-WorkerPool-WorkerNode.html)

## See also

- [Scaling overview](../../scaling/overview) — worker pool topology, hash ring
  assignment, and multi-process deployment
- [ActorSystem](actor-system) — the single-process counterpart; `WorkerNode::system()`
  returns one per thread
