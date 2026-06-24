---
sidebar_position: 8
title: Scaling out
related:
  - runtimes/overview
  - scaling/overview
  - best-practices/passivation
  - best-practices/pooled-connections
---

# Scaling out

Nexus's scaling story is layered — you pay for what you need, and you don't restructure your actor code when the topology changes.

## The runtime ladder

| Runtime | Concurrency model | When to use it |
|---|---|---|
| `FiberRuntime` | PHP 8.5 native fibers, single-thread cooperative scheduler | Development, tests, small services |
| `SwooleRuntime` | Swoole coroutines, single thread | Real async I/O (Postgres, Redis, HTTP clients) on one core |
| Worker pool (Swoole threads) | N independent actor systems per process, consistent-hash routing | Multi-core scale-out on one machine |
| `nexus-cluster` | Remote actor contracts (transport in progress) | Multi-machine deployments |

The first three are stable. The cluster contracts are shipped so your actor code is forward-compatible with the transport when it lands.

## Scale vertically first

Before reaching for the worker pool, exhaust one `SwooleRuntime`. Swoole's coroutine scheduler keeps a single PHP process busy across thousands of concurrent requests on I/O-bound workloads. On a 4-core box, four Swoole worker processes will saturate the CPU and serve far more traffic than four threads each running one fiber.

The signal that you've maxed out vertical: CPU pegged at `worker_num × ~100%`. Until then, raise `worker_num` before adopting the thread pool.

## When you need the worker pool

Two situations push you to `nexus-worker-pool-swoole`:

1. **Cross-thread state with single-writer semantics.** The worker pool's consistent-hash ring guarantees that owner `alice` always lands on the same worker thread. Pure Swoole workers don't — the OS or load balancer decides.

2. **CPU-bound workloads.** Cryptography, image processing, complex aggregations. Multiple threads with shared-nothing actor isolation is the answer; the worker pool provides the routing on top.

The worker pool's transport (`ThreadQueueTransport`) passes `Envelope` objects directly between worker threads without serialisation — you don't pay for JSON-encoding intra-machine RPC.

## Sharding actor ids

The hash ring uses CRC32 of the actor name with 150 virtual nodes per worker. For most workloads this is balanced enough to ignore. If your traffic is highly skewed — one customer drives 40% of writes — you'll hot-spot one worker.

Two mitigations:

**Composite ids.** Instead of `wallet-alice`, use `wallet-alice-shard-{0..7}`. The ring spreads the actor across 8 workers. You give up "alice has one writer everywhere" in exchange for "alice has 8 writers that don't contend." Acceptable for sufficiently independent operations such as idempotent appends.

**Active rebalance.** Track per-worker queue depth; if one exceeds a threshold, evict the hot actor from that worker and respawn it elsewhere. More complex; rarely worth it for normal workloads.

## The three scaling knobs

When traffic grows, turn these in order:

1. **Pool sizes.** `ConnectionPool` and `EntityManagerPool` `max` — raise until Postgres complains or memory tightens. Cheapest change; no code change required.
2. **Worker count.** `SwooleConfig::workerNum` or `WorkerPoolConfig::withThreads(N)`. One per core is a good starting point.
3. **Passivation timing.** Shorter `ReceiveTimeout` → lower resident actor count → more memory headroom → more concurrent traffic capacity. This is a behaviour change that may affect latency, so tune it last.

## Anti-patterns at scale

| Anti-pattern | Symptom | Fix |
|---|---|---|
| One global "router" actor in front of everything | One mailbox bottlenecks all traffic | Route at the HTTP layer; spawn directly |
| Synchronous cross-worker `ask` chains | Coroutine starvation, mysterious 504s | Use `tell` + reply message, or `Future::all` |
| Same actor id touching many workers | Defeats single-writer guarantee; pool thrash | Hash on a stable key (user id, not request id) |
| Pool sized for steady-state, no burst headroom | Burst → 503 → retry storm → worse | Size for p99 burst × 1.5 |
| Actor count grows with users, not with concurrency | Memory creeps; OOMs at 3am | Set `ReceiveTimeout` on every entity actor |
| Logging from inside hot actors at INFO | Log subsystem becomes the bottleneck | Use `NexusLogger` (mailbox-backed) and raise the threshold |

## Multi-machine: the contracts

The `nexus-cluster` package ships the interfaces for remote actors today:

```php title="src/Cluster/ClusterSetup.php"
// NodeAddress, ClusterTransport, NodeDirectory, NodeHashRing
// are all in nexus-cluster. Actor code that uses WorkerActorRef
// works against a future cluster transport without modification.
$nodeAddress = new NodeAddress(
    cluster: 'prod',
    datacenter: 'eu-west-1',
    application: 'wallet',
    node: 'node-1',
);
```

Application code using `WorkerActorRef` works against a future cluster transport without change. The TCP transport is the missing piece; until it lands, you can write your own `ClusterTransport` and plug it into `WorkerNode`.

## Next steps

- [Scaling overview](../scaling/overview.md) — topology diagrams and cross-worker message flow
- [Passivation and memory](./passivation.md) — keeping resident actor count proportional to concurrent activity
- [Pooled connections behind actors](./pooled-connections.md) — connection count as a scaling constraint
