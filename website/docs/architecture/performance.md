---
sidebar_position: 3
title: Performance
---

# Performance

:::caution Work in Progress
Nexus is under active development. These benchmarks reflect the current state
of the codebase and may change as optimizations are added.
:::

All benchmarks run inside Docker on an Apple M4 Max (16 cores, 128 GB RAM),
PHP 8.5.3, Swoole 6.0. Numbers are from the automated PHPUnit performance
test suite (`tests/Performance/`).

### Message throughput

How many messages per second a single actor can process end-to-end (tell ->
mailbox -> behavior handler):

| Benchmark | Fiber | Swoole |
|---|---|---|
| 100K messages to one actor | **1.16M** msgs/sec | **929K** msgs/sec |
| 50K message burst | **1.29M** msgs/sec | **909K** msgs/sec |
| 100K stateful transitions | **1.06M** msgs/sec | **853K** msgs/sec |
| Fan-out (100 actors x 100 msgs) | **1.06M** msgs/sec | **659K** msgs/sec |
| Multi-dispatch (50 x 100 rounds) | **998K** msgs/sec | **574K** msgs/sec |

Fiber is faster in single-process benchmarks because it avoids Swoole's
coroutine scheduling overhead. Swoole's advantage is true async I/O and
multi-worker scaling — not single-process throughput.

### Dispatch rate

Raw `tell()` throughput without waiting for processing:

| Runtime | Dispatch rate |
|---|---|
| Fiber | **5.14M** tells/sec |
| Swoole | **995K** tells/sec |

### Actor lifecycle

| Operation | Fiber | Swoole |
|---|---|---|
| Spawn 1,000 actors | **453K** ops/sec (2.2 us/actor) | **471K** ops/sec (2.1 us/actor) |
| Kill 500 actors (PoisonPill) | **165K** ops/sec | **107K** ops/sec |
| 500 spawn-kill cycles | **151K** ops/sec | **98K** ops/sec |

### Ping-pong latency

Round-trip time for a message sent to an actor that replies immediately:

| Runtime | Latency | Throughput |
|---|---|---|
| Fiber | **2.5 us** per round trip | 399K ops/sec |
| Swoole | **2.5 us** per round trip | 407K ops/sec |

### Memory

| Runtime | Memory per actor |
|---|---|
| Fiber | **3,884 bytes** |
| Swoole | **3,164 bytes** |

At ~3-4 KB per actor, 100K actors consume roughly 300-400 MB.

## Multi-worker scaling (Swoole threads)

Cross-worker messaging through `Thread\Queue` (one inbox per worker) with a shared `Thread\Map` actor directory:

| Metric | Result |
|---|---|
| Cross-worker throughput | **260K** msgs/sec per worker pair |
| Cross-worker round-trip latency | **20 us** per round trip |
| Fan-out (4 workers, 5K messages) | **188K** msgs/sec aggregate |

### Envelope delivery

The worker pool passes `Envelope` objects through `Thread\Queue`. PHP serializes each object on push and deserializes on pop — this is the primary throughput ceiling for cross-thread messaging (see [Hot-path breakdown](#hot-path-component-breakdown) below).

## PHP OPcache JIT

PHP's JIT compiler reduces interpreter overhead for hot loops and pure-PHP arithmetic.
The Nexus `php-swoole` Docker image enables JIT automatically:

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=64M
```

Effect on the world benchmark (16 workers · 16 senders, Apple M4 Max, Docker):

| JIT mode | Throughput |
|---|---|
| JIT disabled (default CLI) | ~3.1M orders/sec |
| JIT tracing | **~3.5M orders/sec** |

JIT is most effective on the pure-PHP hot paths: actor handler closures,
`ActorPath` string operations, and `Behavior` dispatch. Cross-thread
serialization (`Thread\Queue`) is a native C operation and is unaffected.

**ZTS compatibility.** PHP 8.5 ZTS supports JIT. Each thread benefits from
pre-compiled hot functions in the shared OPcache region. No configuration
differences are needed between single-threaded and multi-threaded deployments.

**Production deployments.** When not using the Nexus Docker image, add the
above `opcache.*` settings to `php.ini` or a `conf.d` override file. PHP CLI
disables OPcache by default; `opcache.enable_cli=1` is required.

## Hot-path component breakdown

Each message through the worker pool passes through five stages. The table below
shows the measured cost of each stage in isolation (JIT tracing enabled,
Apple M4 Max, Docker, 300K iterations after warmup):

| Stage | Component | µs/op | M/s ceiling |
|---|---|---|---|
| **Producer** | `ActorPath::root()` cache hit | 0.01 | 86 |
| | `random_bytes(16) + bin2hex()` — Envelope ID | 0.18 | 5.6 |
| | `Envelope::of()` (rand + alloc + 3 fields) | 0.36 | 2.8 |
| | `Thread\Queue::push` (PHP serialize) | 0.61 | 1.6 |
| **Worker** | `Thread\Queue::pop` (PHP unserialize) | 0.98 | 1.0 |
| | `Channel::push()+pop()` (SwooleMailbox) | 0.07 | 15 |
| | `ActorCell` dispatch overhead | 0.06 | — |
| | Full `BehaviorWithState` handler + apply | 0.13 | 7.7 |

### Critical-path analysis

Producer and worker run concurrently on separate OS threads. Only the slower
side constrains throughput:

```
Producer:  Envelope::of() + serialize   =  0.97 µs
Worker:    unserialize + actor dispatch  =  1.24 µs  ← bottleneck
```

At 1.24 µs per message, each worker's theoretical ceiling is **0.81 M/s**, giving
**12.9 M/s** across 16 workers. The measured 3.5 M/s is ~27% of this ceiling —
the gap is **Thread\Queue mutex contention** under concurrent load. Single-threaded
micro-benchmarks do not capture the synchronization overhead between 16 sender
threads and 16 worker threads.

### What limits throughput

**Thread\Queue serialization (1.59 µs/msg total)** is the structural ceiling.
`Thread\Queue` uses PHP's native `serialize()`/`unserialize()` internally; this
cannot be configured or replaced without changes to Swoole. Reducing the
serialized payload size (e.g., compact `ActorPath` serialization) saves bytes
but does not materially improve throughput because the bottleneck is PHP
interpreter overhead, not data transfer time.

### Secondary hotspots

| Hotspot | Cost | Fixable? |
|---|---|---|
| `random_bytes(16)` per `Envelope` — one `getrandom()` syscall/msg | 0.18 µs | Yes — thread-local PRNG eliminates the syscall at the cost of non-CSPRNG IDs |
| `BehaviorWithState::next()` — 1 PHP object alloc/msg | 0.08 µs | Partially — could cache a static same-state singleton to avoid allocation on no-change paths |
| `applyStatefulBehavior()` — `isStopped() + hasNewState() + state()` | 0.02 µs | Low priority — direct nullable field access, already very fast |

### Running the breakdown yourself

```bash
docker compose exec php-swoole php \
  -d opcache.enable_cli=1 -d opcache.jit=tracing \
  -d opcache.jit_buffer_size=64M \
  tests/Performance/hotpath_breakdown.php
```

## Running benchmarks

```bash
# All benchmarks (requires Swoole container)
docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance

# Fiber-only benchmarks (no Swoole needed)
docker compose exec php vendor/bin/phpunit --testsuite=performance --filter=Fiber

# Cluster benchmarks only
docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance --filter=Cluster
```

## Interpreting the numbers

**Fiber vs Swoole:** Fiber is faster in isolated single-process benchmarks.
This does not mean Fiber is "better" — Swoole provides true async I/O
(database, HTTP, filesystem), multi-worker scaling, and native coroutine
support. Use Fiber for development and moderate workloads. Use Swoole for
production with I/O-bound or multi-core workloads.

**Docker overhead:** Benchmarks run inside Docker containers. Native performance
on the host machine is typically 10-20% faster.

**Message size:** All benchmarks use small messages (`(object)['seq' => $i]`).
Larger messages will reduce throughput due to serialization and memory copy costs.
