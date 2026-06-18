# Scaling Overview

Nexus scales to multiple CPU cores on the same machine using a thread-based worker pool.
Each worker thread runs an independent `ActorSystem`. Actors are distributed across workers
via a consistent hash ring. Messages between workers are delivered as `Envelope` objects
directly through `Swoole\Thread\Queue` — no serialization step.

## Prerequisites

- ZTS (Zend Thread Safety) PHP 8.5+
- Swoole 6.0+ compiled with `--enable-swoole-thread`

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│  WorkerPoolBootstrap (main thread)                              │
│  Thread\Map (shared directory)   Thread\Queue[0..N-1] (inboxes) │
└──────────────────┬──────────────────────────────────────────────┘
                   │ Thread\Pool spawns N threads
     ┌─────────────┼─────────────┐
     ▼             ▼             ▼
  Worker 0      Worker 1      Worker 2
  ActorSystem   ActorSystem   ActorSystem
  WorkerNode    WorkerNode    WorkerNode
```

### Key components

- **`WorkerNode`** — Coordinator for one worker. On `spawn()`, consults the hash ring to
  decide whether the actor lives locally or on another worker. Registers the result in the
  shared `WorkerDirectory`.
- **`ConsistentHashRing`** — Maps actor names to worker IDs via CRC32 with 150 virtual
  nodes per worker for uniform distribution.
- **`WorkerActorRef`** — Implements `ActorRef<T>`. For actors on other workers, `tell()`
  wraps the message in an `Envelope` and pushes it to the target worker's `Thread\Queue`.
  No serializer; `Thread\Queue` handles the internal copy.
- **`ThreadQueueTransport`** — One `Swoole\Thread\Queue` per worker as inbox. A
  coroutine-based receive loop with adaptive backoff polls the queue and delivers
  incoming envelopes to local actor mailboxes.
- **`ThreadMapDirectory`** — Shared `Swoole\Thread\Map` mapping actor path strings to
  worker IDs. All threads read and write the same map; `Thread\Map` handles synchronization.

## Message flow

### Local delivery (actor on same worker)
```
tell() → Envelope → LocalActorRef → mailbox → handler
```

### Cross-worker delivery
```
tell() → Envelope → WorkerActorRef
  → ThreadQueueTransport.send(targetWorker, envelope)
  → Thread\Queue[targetWorker].push(envelope)      (Thread\Queue copies object)
  → receive loop on target worker
  → LocalActorRef.enqueueEnvelope(envelope)
  → mailbox → handler
```

## Location transparency

`WorkerNode.spawn()` returns an `ActorRef<T>`. Whether the actor lives on this worker or
another, the caller uses the same interface:

```php
$ref = $node->spawn(Props::fromBehavior($behavior), 'orders');
$ref->tell(new PlaceOrder($items));  // identical regardless of which worker owns 'orders'
```

## Getting started

The fastest way to start a worker pool is the `WorkerPool` DSL builder in
`nexus-worker-pool-swoole`:

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPool;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

WorkerPool::create()
    ->actor('orders', OrderActor::class)
    ->actor('payments', PaymentActor::class)
    ->onStart(static function (WorkerNode $node): void {
        // runs on every worker thread after ActorSystem boots
    })
    ->run(WorkerPoolConfig::withThreads(swoole_cpu_num()));
```

`WorkerPool::actor()` registers a class-based actor on every worker. The hash ring
ensures a given actor name always resolves to the same worker thread, so state is
local within each worker.

For closure-based actors:

```php
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;

WorkerPool::create()
    ->behavior('counter', static fn (): Props => Props::fromBehavior(
        Behavior::withState(0, static fn ($ctx, $msg, $n) => BehaviorWithState::next($n + 1)),
    ))
    ->run(WorkerPoolConfig::withThreads(4));
```

See [Worker Pool Swoole package reference](../packages/worker-pool-swoole.md) for all
builder methods.

## Ask protocol (cross-worker request/response)

`WorkerActorRef::ask()` supports request-response across threads. The ask protocol
uses a reservation slot on the sending worker:

```
Sender calls ask() → WorkerAskRequest sent to target worker via Thread\Queue
  → Target processes request, sends WorkerAskReply back to sender's queue
  → Sender's WorkerNode resolves the Future slot
  → ask() caller receives the result
```

The protocol is fault-tolerant: if no reply arrives within a configurable timeout, the
request times out. See [Worker Pool package reference](../packages/worker-pool.md) for
the full ask protocol API.

## Performance characteristics

- **Cross-worker throughput**: ~260K messages/sec per worker pair (no serialization step)
- **Cross-worker latency**: ~20 µs round-trip
- **Worker count**: set to the number of available CPU cores for CPU-bound workloads

## Multi-machine clustering

For distributing actors across multiple machines over TCP, see the `nexus-cluster` package.
It provides the `ClusterTransport`, `NodeDirectory`, and `NodeHashRing` contracts — the
same interface shape as `WorkerTransport` and `WorkerDirectory` but addressed by
`NodeAddress` (cluster/datacenter/application/node) rather than worker ID.
A TCP transport implementation is deferred to a future package.
