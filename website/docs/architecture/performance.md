---
sidebar_position: 3
title: Performance
---

# Performance

All benchmarks run inside Docker on an Apple M4 Max (16 cores, 128 GB RAM),
PHP 8.5.3, Swoole 6.0. Numbers are from the automated PHPUnit performance
test suite (`tests/Performance/`).

:::caution
These benchmarks reflect the current state of the codebase and may change as
optimizations are added.
:::

## Message throughput

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
multi-process scaling — not single-process throughput.

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

## Multi-process scaling (Swoole)

Cross-worker messaging through Unix domain sockets with `CompactClusterSerializer`:

| Metric | Result |
|---|---|
| Cross-worker throughput | **260K** msgs/sec per worker pair |
| Cross-worker round-trip latency | **20 us** per round trip |
| Serialization throughput | **1.18M** serialize+deserialize cycles/sec |
| Fan-out (4 workers, 5K messages) | **188K** msgs/sec aggregate |

### Wire format

The `CompactClusterSerializer` sends actor paths as raw UTF-8 strings and only
calls PHP `serialize()` on the message object:

```text
[2B: target path length][target path bytes][2B: sender path length][sender path bytes][message bytes]
```

This is ~6x smaller than serializing the full `Envelope` object graph with PHP's
native `serialize()`.

### Read buffering

`UnixSocketTransport` receives up to 64 KB at a time and parses multiple
length-prefixed frames from the buffer. This reduces read syscalls from 2 per
message (header + payload) to roughly N per buffer-full.

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
(database, HTTP, filesystem), multi-process scaling, and native coroutine
support. Use Fiber for development and moderate workloads. Use Swoole for
production with I/O-bound or multi-core workloads.

**Docker overhead:** Benchmarks run inside Docker containers. Native performance
on the host machine is typically 10-20% faster.

**Message size:** All benchmarks use small messages (`(object)['seq' => $i]`).
Larger messages will reduce throughput due to serialization and memory copy costs.
