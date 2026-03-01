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

## Performance characteristics

- **Cross-worker throughput**: ~260K messages/sec per worker pair (no serialization step)
- **Cross-worker latency**: ~20 µs round-trip
- **Worker count**: set to the number of available CPU cores for CPU-bound workloads

## Future: multi-machine clustering

For distributing actors across multiple machines over TCP, see the `nexus-cluster` package.
It provides the `ClusterTransport`, `NodeDirectory`, and `NodeHashRing` contracts.
A TCP-based implementation will arrive in a future `nexus-cluster-swoole` package.
