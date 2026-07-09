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
| Runtime | PHP 8.5.7 (ZTS) · Swoole 6.2.1 · ext-msgpack 3.x · JIT disabled (Xdebug-free image) |
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

# Hot-path component breakdown (per-stage µs attribution)
docker compose exec php-swoole php tests/Performance/cluster_tcp_hotpath.php
```

## Per-core results

**Cross-node tell throughput** (one-way, fire-and-forget), payload sweep:

| Payload | Throughput | Bandwidth | (before tuning) |
|---|---|---|---|
| 64 B | **~84,800 msg/s** | 5.2 MB/s | 33,500 msg/s |
| 1 KB | **~84,100 msg/s** | 82.2 MB/s | 31,600 msg/s |
| 16 KB | **~57,400 msg/s** | 896 MB/s | 424 MB/s |

Throughput is roughly flat in message *count* until payloads get large — the per-message cost
(serialize + frame + syscall + deserialize) dominates over the bytes until ~16 KB, where bandwidth
climbs to ~896 MB/s and the copy cost starts to matter. The "before tuning" column is the first
benchmark run of the same stack; the [profiling section below](#finding-the-bottlenecks) explains
where the 2.7× came from.

**Cross-node ask round-trip latency** (request → reply, one in flight):

| mean | p50 | p90 | p99 | p99.9 | max | (before: p50 / p99) |
|---|---|---|---|---|---|---|
| 33.1 µs | **32.0 µs** | 37.5 µs | **45.9 µs** | 114 µs | 1.8 ms | 69.3 / 89.4 µs |

That is a full round trip — serialize, frame, loopback TCP, deserialize, actor hop, reply, and back —
at **sub-50 µs through p99**. Sequential ask/reply (one outstanding) yields ~30,200 asks/s; real
throughput is far higher with requests in flight concurrently.

**Local short-circuit baseline** — a `ClusterRef` addressed to an actor on the *same* node skips the
wire entirely: **~340,000 msg/s**. The remaining gap between this and the cross-node figure is the
transport (msgpack + framing + loopback TCP) plus the shared-reactor contention of the single-process
harness — the actor delivery itself is cheap.

## Finding the bottlenecks

The tuned numbers above did not come from guessing. A component profiler
(`tests/Performance/cluster_tcp_hotpath.php`) prices every userland stage of the remote path in
isolation, so each microsecond of the measured per-message budget is *attributed*, not assumed:

| Stage (per 1 KB message) | µs/op | % of the 31.6 µs budget |
|---|---|---|
| envelope deserialize (Valinor TreeMapper) | **11.94** | **37.8%** |
| body decode (user message, Valinor) | 3.41 | 10.8% |
| envelope serialize (Valinor normalizer) | 2.94 | 9.3% |
| user message encode | 1.19 | 3.8% |
| per-message liveness pipeline | 1.34 | 4.2% |
| telemetry no-op paths | ~0.06 | ~0.2% |
| *unaccounted (sockets, scheduler, mailbox hop)* | 10.64 | 33.7% |

Two findings drove the two fixes:

1. **Serialization was 62% of the entire budget**, and the single worst stage was hydrating the
   fixed six-field `MessagePayload` envelope through Valinor's reflection-driven TreeMapper.
   The fix — a hand-rolled `MessagePayloadCodec` that packs/unpacks the same msgpack map directly —
   cut the envelope cost from 14.9 µs to 1.3 µs per message (unpack alone: 15.6× faster), while
   keeping wire compatibility (both codecs emit the same map; readers resolve by key) and strict
   per-field validation at the trust boundary. User message bodies deliberately stay on the generic
   Valinor path: arbitrary types justify a mapper; a fixed internal envelope does not.
2. **Every inbound frame fired a liveness message at the membership actor** — one allocation,
   one mailbox hop, one clock read, one phi update, and one `ClusterView` rebuild *per frame* —
   even though the phi detector discards inter-arrival samples closer than its 50 ms floor, so
   per-frame beats carried no detection value. Worse than the CPU cost, this was a **reliability
   hazard**: under sustained traffic the membership mailbox floods and gossip/failure-detection
   processing queues behind thousands of stale beats — the detector reads stale arrival times
   exactly when the cluster is busiest. `LivenessThrottle` coalesces to at most one observation
   per peer per 50 ms; a 200-message burst now produces a handful of observations instead of 200+
   (guarded by a regression test validated to fail with the throttle disabled).

Equally important is what the profiler said **not** to fix: the no-op telemetry path costs ~0.06 µs
per message — the "cache the counters" optimization that looked plausible on paper would have been
noise. Measure first.

A third, smaller win followed: adding `ext-msgpack` to the Docker image (auto-detected by
`MsgpackCodec`) roughly halved the raw pack/unpack cost again — worth a further ~14% end-to-end.
Still on the table for future work: the ~10 µs unaccounted remainder (socket syscalls, coroutine
scheduling, and the mailbox hop — per-frame write batching would attack the syscall share) and the
Valinor share of user-body decode.

## Whole-machine scaling

Aggregate 1 KB throughput as *K* parallel two-node clusters run at once (so `2K` nodes total):

| K workers | Nodes | Aggregate msg/s | Bandwidth | Peak CPU |
|---|---|---|---|---|
| 1 | 2 | 78,314 | 76.5 MB/s | 122% |
| 2 | 4 | 157,090 | 153.4 MB/s | 99% |
| 4 | 8 | 306,072 | 298.9 MB/s | 219% |
| 8 | 16 | 582,574 | 568.8 MB/s | 496% |
| 12 | 24 | **877,859** | **857.4 MB/s** | 799% |
| 16 | 32 | **946,426** | **924.2 MB/s** | 1639% |

Throughput scales **near-linearly** — ~1.95× at K=2, ~3.8× at K=4, ~7.7× at K=8 — and reaches
**~878,000 msg/s at K=12** (the M4 Max has **12 performance cores**). With ext-msgpack lightening the
per-message CPU, the 4 efficiency cores now add real headroom beyond that — K=16 tops out at
**~946,000 msg/s**. The clean plateau at the P-core count is the important result: **the transport has no global
lock or shared bottleneck — it scales with cores.** (The pre-tuning run showed the same shape at a
~315K msg/s plateau; the 3× lift is the per-core tuning multiplied across every core.)

So on this laptop the mesh sustains roughly **945K small messages/second (~925 MB/s)** across the machine,
and would go higher on bare-metal Linux or with the two sides of each pair on separate hosts.

## Is this usable? An honest comparison

Short answer: **yes, for its niche — and comfortably better than the usual PHP alternatives — but it is
not, and does not try to be, a JVM/BEAM/Go-class distributed actor runtime.**

Rough, order-of-magnitude context (small remote messages, single node/pair; public figures vary wildly
with tuning and hardware, so treat these as ballpark):

| System | Remote small-msg throughput | Round-trip latency | Notes |
|---|---|---|---|
| **Nexus cluster-tcp** (this) | ~84K/core, ~945K/machine | ~32 µs p50 (loopback) | PHP 8.5 + Swoole + ext-msgpack |
| Akka / Pekko (JVM, Artery) | ~hundreds of K – ~1M+ /node | tens–hundreds of µs | JIT, off-heap, battle-tested at scale |
| Erlang/OTP (BEAM dist) | ~hundreds of K /node | low µs–ms | purpose-built for distribution |
| Proto.Actor (Go) | ~hundreds of K remote | tens–hundreds of µs | native, gRPC transport |
| Orleans (.NET) | ~tens of K grain calls/silo | sub-ms–low ms | virtual-actor model |
| **PHP + broker** (RabbitMQ/Redis) | ~tens of K/s | **~1–10 ms** | extra network hop + broker |
| **PHP-FPM HTTP** call | ~thousands req/s/box | **~ms–tens of ms** | per-request bootstrap |

What this means in practice:

- **Against other actor runtimes**, Nexus is roughly **3–12× slower per node** for raw remote throughput.
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
at a scale of **~950K messages per second per machine**, which covers the overwhelming
majority of PHP workloads.

**Where it is not the answer (yet):** ultra-high-throughput streaming, microsecond-latency-critical paths,
or clusters that need quorum/consensus and strong consistency — C1 is an AP mesh with no built-in
consensus (see the [clustering guide](./clustering-over-tcp.md) and the trust model in the
[package reference](../packages/cluster-tcp.md#security--trust-model)).

So: not "totally unusable" — the opposite. For a PHP system it is a genuinely useful distribution layer
with sub-millisecond in-cluster calls and clean linear scaling, provided you size it against PHP-ecosystem
expectations rather than the JVM's.
