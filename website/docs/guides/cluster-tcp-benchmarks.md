---
title: Cluster TCP benchmarks
description: Throughput, latency, and scaling of the nexus-cluster-tcp mesh — with an honest comparison to other actor systems and the usual PHP alternatives.
---

# Cluster TCP benchmarks

This page reports measured throughput, round-trip latency, and multi-core scaling for
`nexus-cluster-tcp`, together with the methodology and a candid comparison to other
actor systems and the alternatives a PHP team would otherwise reach for.

:::caution Read the methodology before the numbers
These are **single-machine, Docker-on-macOS loopback** measurements. They characterise
the *software's* intrinsic cost — serialization, framing, the TCP stack, the actor
mailbox, and the coroutine scheduler — not a production multi-host deployment, which adds
real network latency on top. Numbers are order-of-magnitude guidance, not a datasheet.
:::

## Test environment

| | |
|---|---|
| Machine | Apple MacBook Pro 16", **M4 Max** (12 performance + 4 efficiency cores), 128 GB RAM |
| Host OS | macOS; benchmarks run inside the `php-swoole` container under **Docker Desktop** (Linux VM) |
| Runtime | PHP 8.5.7 (ZTS) · Swoole 6.2.1 · JIT disabled (Xdebug-free image) |
| Transport | `SwooleMeshTransport` over TCP on `127.0.0.1`, MessagePack payloads, length-prefixed frames |

Two things to keep in mind throughout:

- **Docker Desktop runs a Linux VM**, so even "native" here is virtualised. Bare-metal Linux
  on the same silicon would be faster.
- In the single-process tests **both cluster nodes share one Swoole reactor (one core)**, so the
  sender's and receiver's work compete for the same CPU. A real two-host deployment runs them on
  separate cores and would roughly double per-pair throughput — at the cost of real network latency.

## Methodology

Two complementary harnesses, both committed under `tests/Performance/`:

1. **Per-core efficiency** (`ClusterTcpPerformanceTest`) — two `ClusterNode`s in *one* process over
   real loopback TCP. Every message takes the full remote path: MessagePack serialize → frame →
   loopback TCP → deserialize → mailbox → handler. One reactor, so this is the cost on a **single core**.
2. **Whole-machine saturation** (`cluster_tcp_saturation.sh` + `cluster_tcp_bench_worker.php`) — launches
   *K* independent two-node clusters in parallel. One reactor pins ~one core, so *K* workers exercise *K*
   cores and the aggregate throughput shows how the stack **scales with cores**.

Every measurement **warms up** first (2,000 tells / 1,000 asks) to prime the TCP link, the phi-accrual
window, and opcache before timing. Latency percentiles are computed from per-request `hrtime(true)`
samples. Throughput is measured end-to-end: the clock stops only when the receiver has delivered the
last message, so it includes drain time, not just the send loop.

Reproduce:

```bash
# Per-core throughput + latency
docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance --filter=ClusterTcp

# Whole-machine saturation sweep (msgs/worker, payload bytes)
./tests/Performance/cluster_tcp_saturation.sh 100000 1024
```

## Per-core results

**Cross-node tell throughput** (one-way, fire-and-forget), payload sweep:

| Payload | Throughput | Bandwidth |
|---|---|---|
| 64 B | ~33,500 msg/s | 2.0 MB/s |
| 1 KB | ~31,600 msg/s | 30.8 MB/s |
| 16 KB | ~27,100 msg/s | 424 MB/s |

Throughput is roughly flat in message *count* until payloads get large — the per-message cost
(serialize + frame + syscall + deserialize) dominates over the bytes until ~16 KB, where bandwidth
climbs to ~424 MB/s and the copy cost starts to matter.

**Cross-node ask round-trip latency** (request → reply, one in flight):

| mean | p50 | p90 | p99 | p99.9 | max |
|---|---|---|---|---|---|
| 71 µs | 69 µs | 78 µs | 89 µs | 351 µs | 4.5 ms |

That is a full round trip — serialize, frame, loopback TCP, deserialize, actor hop, reply, and back —
at **sub-100 µs through p99**. Sequential ask/reply (one outstanding) yields ~14,000 asks/s; real
throughput is far higher with requests in flight concurrently.

**Local short-circuit baseline** — a `ClusterRef` addressed to an actor on the *same* node skips the
wire entirely: **~304,000 msg/s**. The ~10× gap between this and the ~31,600 msg/s cross-node figure is
the cost of the transport plus the shared-reactor contention — the actor delivery itself is cheap; the
wire is where the time goes.

## Whole-machine scaling

Aggregate 1 KB throughput as *K* parallel two-node clusters run at once (so `2K` nodes total):

| K workers | Nodes | Aggregate msg/s | Bandwidth | Container CPU |
|---|---|---|---|---|
| 1 | 2 | 31,173 | 30.4 MB/s | 100% |
| 2 | 4 | 61,177 | 59.7 MB/s | 201% |
| 4 | 8 | 119,096 | 116.4 MB/s | 403% |
| 8 | 16 | 234,473 | 228.8 MB/s | 806% |
| 12 | 24 | **315,747** | **308.3 MB/s** | 1214% |
| 16 | 32 | 314,697 | 307.2 MB/s | 1547% |

Throughput scales **near-linearly** — ~1.96× at K=2, ~3.8× at K=4, ~7.5× at K=8 — and then plateaus at
**~315,000 msg/s around K=12**. That is not a coincidence: the M4 Max has **12 performance cores**. Beyond
K=12 the only cores left are the 4 efficiency cores, which raise CPU% (to ~1547%) without adding
throughput. The clean plateau at the P-core count is the important result: **the transport has no global
lock or shared bottleneck — it scales with cores.**

So on this laptop the mesh sustains roughly **300K small messages/second (~300 MB/s)** across the machine,
and would go higher on bare-metal Linux or with the two sides of each pair on separate hosts.

## Is this usable? An honest comparison

Short answer: **yes, for its niche — and comfortably better than the usual PHP alternatives — but it is
not, and does not try to be, a JVM/BEAM/Go-class distributed actor runtime.**

Rough, order-of-magnitude context (small remote messages, single node/pair; public figures vary wildly
with tuning and hardware, so treat these as ballpark):

| System | Remote small-msg throughput | Round-trip latency | Notes |
|---|---|---|---|
| **Nexus cluster-tcp** (this) | ~31K/core, ~315K/machine | ~70 µs p50 (loopback) | PHP 8.5 + Swoole, userland MessagePack |
| Akka / Pekko (JVM, Artery) | ~hundreds of K – ~1M+ /node | tens–hundreds of µs | JIT, off-heap, battle-tested at scale |
| Erlang/OTP (BEAM dist) | ~hundreds of K /node | low µs–ms | purpose-built for distribution |
| Proto.Actor (Go) | ~hundreds of K remote | tens–hundreds of µs | native, gRPC transport |
| Orleans (.NET) | ~tens of K grain calls/silo | sub-ms–low ms | virtual-actor model |
| **PHP + broker** (RabbitMQ/Redis) | ~tens of K/s | **~1–10 ms** | extra network hop + broker |
| **PHP-FPM HTTP** call | ~thousands req/s/box | **~ms–tens of ms** | per-request bootstrap |

What this means in practice:

- **Against other actor runtimes**, Nexus is roughly **10–50× slower per node** for raw remote throughput.
  That is exactly what you would expect: PHP with userland serialization and coroutine scheduling versus
  JIT-compiled JVM/Go or the natively-distributed BEAM. If you need a multi-million-message-per-second
  firehose or microsecond-tail trading latency, use the right tool — this is not it.
- **Against what a PHP team actually reaches for** — a message broker or HTTP between services — Nexus is
  *faster and an order of magnitude lower latency*. A RabbitMQ/Redis hop or an internal HTTP call costs
  **milliseconds**; a `ClusterRef` ask costs **tens of microseconds** on a LAN plus the network RTT. And
  you get location transparency: the same `tell`/`ask` works whether the actor is local or three nodes away.

**Where it is genuinely usable today:** sharded stateful entities (one writer per aggregate, addressed by
identity across the mesh), coordinating workers across machines, real-time features (presence, live
counters, fan-out), and turning a broker-and-HTTP service graph into direct sub-millisecond actor calls —
at a scale of **hundreds of thousands of messages per second per machine**, which covers the overwhelming
majority of PHP workloads.

**Where it is not the answer (yet):** ultra-high-throughput streaming, microsecond-latency-critical paths,
or clusters that need quorum/consensus and strong consistency — C1 is an AP mesh with no built-in
consensus (see the [clustering guide](./clustering-over-tcp.md) and the trust model in the
[package reference](../packages/cluster-tcp.md#security--trust-model)).

So: not "totally unusable" — the opposite. For a PHP system it is a genuinely useful distribution layer
with sub-millisecond in-cluster calls and clean linear scaling, provided you size it against PHP-ecosystem
expectations rather than the JVM's.
