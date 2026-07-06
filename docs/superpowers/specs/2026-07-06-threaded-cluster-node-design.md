# Threaded Cluster Node — Design

**Status:** Approved design (2026-07-06). Implementation DEFERRED until after C1 (TCP mesh) + C2 (receptionist) land. Only the two C1.6 seam requirements are actioned now.

**Goal:** Let a single cluster node be a multi-threaded Nexus app (a Swoole `Thread\Pool` of worker threads) rather than a single-threaded `ActorSystem`, so the topology is `NodeA(threaded app) ↔ TCP cluster ↔ NodeB(threaded app)`. Combines vertical scaling (threads within a node) with horizontal scaling (nodes across machines) under one location-transparent `ActorRef` model.

## Decisions (user-approved)

1. **Identity:** a node = one process = one `NodeAddress` with one advertised endpoint. Worker threads are an internal implementation detail, invisible to remote peers.
2. **Network ownership:** a single dedicated **gateway thread** per node owns all peer TCP connections and gossip membership. It is the only thread with sockets and the only cluster identity.
3. **Sequencing:** design now; add two pluggable seams to C1.6; implement the bridge as its own package after C1+C2.
4. **Inbound routing** reuses the existing worker-pool `ConsistentHashRing` (name→worker), with `ThreadMapDirectory` fallback for dynamically-placed actors.
5. **Serialization** happens on the **worker** thread for outbound (spreads CPU across workers), not centralized on the gateway.

## Topology

```
              ┌─────────────────────── NodeA (process, one NodeAddress) ───────────────────────┐
   TCP mesh   │  gateway thread: ClusterNode (mesh transport + gossip + inbound router)          │
  ◄──────────►│      │  ThreadQueue in/out                                                       │
              │  worker thread 1 (ActorSystem+SwooleRuntime) … worker thread N                    │
              └───────────────────────────────────────────────────────────────────────────────┘
```

Two independent hash rings compose: `NodeHashRing` selects the machine; intra-node `ConsistentHashRing` selects the thread; a coroutine runs the actor.

## Components

- **Gateway thread** — runs `ClusterNode`: `MeshTransport` (Swoole TCP), `MembershipService` (gossip + phi-accrual), and the inbound router. Holds the `PeerConnection` per peer. No user actors of its own (or only system/receptionist actors).
- **Worker threads** — unchanged from `nexus-worker-pool-swoole`: each boots an `ActorSystem` + `SwooleRuntime`, hosts local actors, and routes among peers via `ConsistentHashRing` + `ThreadQueueTransport`.

## Data flow

### Inbound (remote node → local actor)
1. Gateway receives a `MessagePayload` frame over TCP, deserializes the msgpack body → message + metadata (`targetPath`, `correlationId`, `replyPath`, `trace`).
2. Gateway resolves `targetPath` → worker ID via the shared `ConsistentHashRing` (or `ThreadMapDirectory` for dynamic actors).
3. Gateway enqueues an `Envelope` onto that worker's `Thread\Queue` through `ThreadQueueTransport::send(workerId, envelope)`.
4. The worker's existing drain loop delivers the envelope to the local actor's mailbox — identical to a cross-thread `WorkerActorRef` hop, just sourced from the gateway.

### Outbound (local actor → remote node)
1. A worker-thread actor holds a `ClusterRef` for a remote `NodeAddress`.
2. `ClusterRef::tell()` serializes the message to a `MessagePayload` (msgpack, on the worker thread) and enqueues it onto the **gateway's outbound `Thread\Queue`**. Because the payload is already scalar metadata + opaque bytes, it crosses the thread boundary safely.
3. The gateway drains its outbound queue, wraps the payload in a `Frame`, and sends it over the target peer's `PeerConnection`.

### Ask/reply across the boundary
The `senderRef` the gateway injects on an inbound ask is a reply-routing ref whose `tell()` travels back out via the worker→gateway outbound path, keyed by `replyPath` — reusing C1.6's ask correlation registry. No new correlation mechanism.

## The two C1.6 seams (actioned now)

C1.6 must be built so the threaded bridge slots in with zero rework:

1. **Inbound seam** — `InboxRouter` resolves and delivers behind an interface (e.g. `InboundDelivery`), NOT hard-wired to a single `LocalActorRegistry`.
   - Default impl (C1 ships): `LocalActorRegistry`-backed single-`ActorSystem` delivery.
   - Threaded impl (later): resolve path → worker ID via hash ring → enqueue on the worker `Thread\Queue`.
2. **Outbound seam** — `ClusterRef` sends via an `OutboundSink` interface (`send(NodeAddress, MessagePayload): void`), NOT a concrete same-thread `PeerConnection`.
   - Default impl (C1 ships): direct `PeerConnection` send on the same thread.
   - Threaded impl (later): enqueue on the gateway's outbound `Thread\Queue`.

Both are small interface extractions. C1.6 is not yet built, so there is no rework — the requirements are added to the C1.6 task brief.

## Deferred bridge package

`nexus-cluster-tcp-threads` (after C1+C2): a `ThreadedClusterApp` bootstrap wiring 1 gateway thread (ClusterNode + threaded `InboundDelivery` + an outbound-queue pump) + N worker threads (WorkerNode + threaded `OutboundSink`), reusing `ThreadQueueTransport`, `ThreadMapDirectory`, `ConsistentHashRing`. Requires ZTS + Swoole threads (Swoole 6.0+, `--enable-swoole-thread`). Depends on `nexus-cluster-tcp` + `nexus-worker-pool-swoole`.

## Failure modes

- **Gateway thread crash** → the node leaves the cluster (all workers become remotely unreachable); peers detect via phi-accrual and mark it Down. Supervised restart of the gateway thread rejoins.
- **Worker thread crash** → gateway stays up; the inbound resolver dead-letters (counter) messages targeting that worker until it restarts.
- **Backpressure** → gateway↔worker `Thread\Queue`s are bounded; overflow drops + increments a counter, consistent with the existing worker-pool and cluster send-buffer semantics.

## Testing

- **Seam interfaces** (`InboundDelivery`, `OutboundSink`) get pure unit tests with fakes — no threads needed. These ship with C1.6.
- **End-to-end** (in the bridge package, later) needs ZTS + Swoole-threads integration, timeout-wrapped like `integration-worker-pool-swoole`: a 2-node × 2-thread test — register an actor on nodeA's worker, `tell`/`ask` from nodeB, assert delivery on the correct thread and that the reply routes back through the gateway.

## Out of scope (v1)

- Cross-node consistent placement of the *same* logical actor onto a *predictable* thread (each node hashes its own local actors independently).
- Actor migration between threads or nodes.
- Multiple gateway threads / gateway load-balancing.
