---
sidebar_position: 8
title: Scaling out
---

# Scaling out

> *"How do I go from one process to all my CPU cores, then to many
> machines?"*

Nexus's scaling story is layered. You pay for what you need; you don't
restructure your actor code when the topology changes.

## The four runtimes

| Runtime | Concurrency model | When to reach for it |
|---|---|---|
| `FiberRuntime` | PHP 8.5 native fibers, single-thread cooperative scheduler | Development, tests, small services |
| `SwooleRuntime` | Swoole coroutines, single-thread | When you need real async I/O (Postgres, Redis, HTTP clients) and one core is enough |
| `WorkerPool` (Swoole threads) | N independent `ActorSystem`s per process, consistent-hash routing | Multi-core scale-out within one machine |
| `nexus-cluster` | TCP-based remote actors (contracts shipped, transport WIP) | Multi-machine deployments |

The first three are stable today. The fourth is in flight; the
contracts are shipped so application code is forward-compatible.

## Vertical first

Before you reach for the worker pool, exhaust one Swoole runtime.
Swoole's coroutine scheduler keeps a single PHP process busy across
thousands of concurrent requests if the workload is I/O-bound (most
web services are). On a 4-core box, four Swoole worker processes with
`enableCoroutineHook: true` will saturate the CPU and serve far more
traffic than four threads-each-with-one-Fiber would.

The signal that you've maxed out vertical is **CPU pegged at
worker_num × ~100%**. Until then, raise `worker_num` before adopting
the thread pool.

## When you need the worker pool

Two situations push you to `nexus-worker-pool-swoole`:

1. **You need cross-thread state with single-writer semantics.**
   Worker-pool's consistent-hash ring guarantees that owner `alice`
   always lands on the same worker thread. Pure Swoole workers don't —
   the OS or the load balancer decides.

2. **You're CPU-bound, not I/O-bound.** Cryptography, image
   processing, complex aggregations. Multiple PHP processes with the
   same shared-nothing model is the answer; the worker pool gives you
   the routing on top.

The worker-pool's transport (`ThreadQueueTransport`) passes
`Envelope` objects directly between worker threads without
serialisation — ~260K msgs/sec per worker pair. You don't pay for
JSON-encoding intra-machine RPC.

## Sharding actor ids

The hash ring uses CRC32 of the actor name with 150 virtual nodes per
worker. For most workloads this is balanced enough to ignore — but
**if your traffic is highly skewed** (a single VIP customer drives
40% of writes), you'll hot-spot one worker.

Two mitigations:

**1. Composite ids.** Instead of `wallet-alice`, use
`wallet-alice-shard-{0..7}`. The ring spreads alice across 8
workers; the HTTP layer routes by `shard = hash(request_id) % 8`.
You give up the "alice has one writer everywhere" invariant for
"alice has 8 writers but they don't fight" — for sufficiently
independent operations (idempotent appends), this is acceptable.

**2. Active rebalance.** Track per-worker queue depth; if one
exceeds a threshold, evict the hot actor from that worker and
respawn elsewhere. More complex; rarely worth it for normal
workloads.

## Multi-machine: the contracts

The `nexus-cluster` package ships the interfaces you need today:

```php
class NodeAddress { /* cluster + datacenter + application + node */ }
interface ClusterTransport { /* send to remote nodes */ }
interface NodeDirectory { /* map actor → node */ }
NodeHashRing                // consistent hash across nodes
```

Application code that uses `WorkerActorRef` works against a future
cluster transport without change. The transport itself (TCP,
QUIC, whatever) is the missing piece. We're shipping a reference
implementation; until then, you can write your own
`ClusterTransport` and plug it into `WorkerNode`.

## Scaling under pressure: the three axes

When traffic grows, you have three things to turn:

1. **Pool sizes.** `ConnectionPool` and `EntityManagerPool` `max` —
   keep climbing until Postgres complains or memory tightens.
2. **Worker count.** `SwooleConfig::workerNum` or
   `WorkerPoolConfig::withThreads(N)`. One per core is a good first
   guess.
3. **Actor passivation timing.** Shorter `ReceiveTimeout` →
   resident count drops → memory headroom up → can take more
   concurrent traffic.

Do them in that order. Pool size is the cheapest knob; worker count
costs memory per worker; passivation is a behaviour change that
might affect latency.

## Anti-patterns at scale

| Anti-pattern | Symptom | Fix |
|---|---|---|
| One global "router" actor in front of everything | One mailbox bottlenecks all traffic | Route at the HTTP layer (hash ring); spawn directly |
| Synchronous cross-worker `ask` chains | Coroutine starvation, mysterious 504s | `tell` + reply message; or `Future::all` |
| Same id touching many workers | Defeats single-writer; pool thrash | Hash on a stable key (user id, not request id) |
| Pool sized for steady-state, no burst headroom | Burst → 503 → retry storm → worse | Size for p99 burst × 1.5 |
| Actor count grows with users, not with concurrency | Memory creeps; OOMs at 3am | Set `ReceiveTimeout` on every entity actor |
| Logging from inside hot actors at INFO | Log subsystem becomes the bottleneck | NexusLogger (mailbox-backed); raise threshold |

## The wallet-app's scaling profile

Concrete numbers from the included example to ground the discussion:

- One process, 4 Swoole worker threads.
- Each thread: own `ActorSystem`, two pools (DBAL + ORM), 8
  connections each, ~10 active `LedgerActor`s under load.
- `withReceiveTimeout(120s)` keeps memory bounded.
- Postgres handles ~64 simultaneous connections fine
  (4 threads × 16 pool slots).
- Single-machine cap: a few thousand concurrent users at typical
  CRUD traffic patterns.

To scale beyond that on the same hardware: raise the pool max and
the thread count. To scale beyond that horizontally: add a second
machine running the same image, put a real database (RDS, Cloud
SQL) behind both, and either accept cross-machine cross-writer
risk (rely on Postgres optimistic locking) or wait for the
TCP cluster transport.
