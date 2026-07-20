---
sidebar_position: 4
title: Performance tuning
related:
  - operations/deployment
  - operations/observability
  - http/servers
  - scaling/overview
---

# Performance tuning

:::caution Indicative numbers only
Nexus is pre-1.0 and not yet production-hardened. The figures on this page are indicative local measurements — validate on your own hardware before relying on them.
:::

A field guide to making Nexus HTTP fast. Out of the box you get approximately 110k RPS on a laptop-class container; reaching single-digit-millisecond tail latency requires tuning at four layers — framework, PHP runtime, Swoole event loop, and the Linux kernel.

The numbers on this page come from `wrk -t8 -c100 -d15s` against `examples/thread-server-no-log.php` (Swoole thread mode, 8 workers, no logging) on the project's standard Docker image.

## Baseline targets

Reasonable targets for an 8-thread Nexus deploy on commodity hardware:

| Metric | Comfortable | Stretch |
|---|---|---|
| Throughput | 80k RPS | 120k RPS |
| Avg latency | < 50 ms | < 30 ms |
| p99 latency | < 100 ms | < 50 ms |
| Max latency | < 1 s | < 250 ms |

The comfortable column is mostly free — install Nexus, write handlers, ship. The stretch column requires the tuning below.

These numbers are measured against an empty-handler workload (`/hello/load` — single JSON response, no I/O). Expect 30–50% reduction for handlers that touch a database, send actor messages, or render templates. The framework overhead stays constant; your application workload dominates from there.

## The four bottlenecks

In production load, latency comes from one of:

1. **Per-request allocations** triggering PHP garbage collection — visible as periodic standard-deviation spikes.
2. **Coroutine scheduler contention** when one slow request starves others — visible as p99 climbing under load even when average stays flat.
3. **Linux TCP defaults** dropping connections under burst — visible as socket-timeout errors and 1-second tails (the kernel SYN-cookie RTO).
4. **OPcache / JIT not warmed up** — visible as cold-start latency.

Apply them in order; later optimizations depend on earlier ones for full effect.

## Framework: closure pre-binding

`HandlerResolver` builds the argument-resolution closure once per handler at compile time, not once per request. Each request calls the captured closure with `(request, scope, pathParams)`, which iterates a `foreach` loop over pre-compiled `ParamMetadata` and calls each resolver's `resolve()` directly.

What this saves per request:

- One method-call indirection
- One closure allocation
- One `ResolverServices` allocation (now captured at compile time)

Measured impact:

| Metric | Per-request `array_map` | Pre-bound closure | Delta |
|---|---|---|---|
| RPS | 108,540 | 112,650 | +3.8% |
| Avg latency | 40.0 ms | 33.8 ms | −15% |
| Stddev | 99 ms | 71 ms | −28% |
| Max | 1.40 s | 1.01 s | −28% |
| Timeouts (15s test) | 14 | 5 | −64% |

The throughput delta is within run-to-run noise; the tail metrics are real and consistent.

## PHP runtime: OPcache and JIT

The default Docker PHP install has OPcache enabled but JIT disabled. Enable JIT:

```ini title="docker/opcache.ini"
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.save_comments=1

opcache.jit=tracing
opcache.jit_buffer_size=128M
opcache.jit_hot_loop=64
opcache.jit_hot_func=127

opcache.preload=/app/preload.php
opcache.preload_user=www-data
```

JIT specifically helps polymorphic dispatch — every `$resolver->resolve($p, $ctx)` is a virtual call that the JIT can trace and inline. Without it, each call costs a vtable lookup.

A minimal preload file for the framework hot path:

```php title="preload.php"
<?php

require __DIR__ . '/vendor/autoload.php';

opcache_compile_file(__DIR__ . '/packages/nexus-http/src/Handler/HandlerResolver.php');
opcache_compile_file(__DIR__ . '/packages/nexus-http/src/Handler/Resolver/ParamResolverRegistry.php');

foreach (glob(__DIR__ . '/packages/nexus-http/src/Handler/Resolver/Builtin/*.php') as $f) {
    opcache_compile_file($f);
}

foreach (glob(__DIR__ . '/packages/nexus-http/src/Middleware/*.php') as $f) {
    opcache_compile_file($f);
}

opcache_compile_file(__DIR__ . '/packages/nexus-http/src/Routing/Dispatcher.php');
```

In production, compile asserts out entirely:

```ini title="docker/opcache.ini"
zend.assertions=-1
```

With `zend.assertions=-1`, PHP compiles `assert(...)` calls out at parse time — any defensive checks in your code or dependencies cost zero at runtime.

## Swoole: server settings

```php title="src/server.php"
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(8)
    ->maxRequest(100_000)
    ->withSwooleSetting([
        'tcp_nodelay'          => true,
        'tcp_defer_accept'     => 1,
        'open_tcp_keepalive'   => 1,
        'tcp_keepidle'         => 60,
        'socket_buffer_size'   => 32 * 1024 * 1024,
        'package_max_length'   => 4 * 1024 * 1024,
        'buffer_output_size'   => 32 * 1024 * 1024,
        'backlog'              => 65535,
    ]);
```

### Preemptive coroutine scheduling

Preemptive scheduling forces every coroutine to yield every few milliseconds, regardless of whether it would otherwise. Enable it at boot, before `Server::start()`:

```php title="src/server.php"
\Swoole\Coroutine::set([
    'enable_preemptive_scheduler' => true,
    'max_coroutine' => 100_000,
]);
```

**When this pays off:** workloads where handler durations vary widely (one 50ms handler blocking 99 fast ones), or where some handlers do genuine CPU-bound work. On those workloads, preemptive scheduling is the single biggest p99 lever on Swoole — 2–5× p99 improvements are typical.

**When it costs you:** uniformly-fast workloads where every coroutine returns in microseconds. The forced yield-checks add measurable overhead without benefit.

**Rule of thumb:** if your p99 is more than 10× your p50, enable it. If your p99 is within 3× of your p50, leave it off.

## Kernel: TCP sysctls

The 1-second `max` latency you see on default Docker is the Linux TCP RTO. Under SYN-queue overflow, the kernel drops connections and the client retries after exactly 1 second.

Fix via `sysctls` in `compose.yaml`:

```yaml title="compose.yaml"
php-swoole:
  build:
    context: .
    dockerfile: docker/Dockerfile
    target: php-swoole
  ports:
    - "8080:8080"
  sysctls:
    net.core.somaxconn: 65535
    net.ipv4.tcp_max_syn_backlog: 65535
    net.ipv4.tcp_tw_reuse: 1
    net.ipv4.ip_local_port_range: "1024 65535"
  ulimits:
    nofile:
      soft: 65535
      hard: 65535
```

The kernel maintains two per-listener queues: the SYN queue (sized by `tcp_max_syn_backlog`) and the accept queue (sized by `somaxconn`). When either fills, the kernel drops the SYN — the client retries after the TCP RTO of approximately 1 second. Raising both queues eliminates this as a source of tail latency.

These four sysctls reduced socket-timeout count in the benchmark from 14 (15s test) to 1 — an 80% drop in tail outliers.

## Measured impact summary

Cumulative impact on the uniformly-fast `/hello/load` benchmark:

| Configuration | RPS | Avg | Stddev | Max | Timeouts |
|---|---|---|---|---|---|
| Baseline (registry, default Docker) | 108k | 40 ms | 99 ms | 1.40 s | 14 |
| + Closure pre-binding | 112k | 34 ms | 71 ms | 1.01 s | 5 |
| + Kernel sysctls | 115k | 33 ms | 68 ms | 1.00 s | 1 |
| + Preemptive coroutines | 115k | 35 ms | 82 ms | 1.40 s | 4 |
| + OPcache + JIT tracing | 117k | 36 ms | 88 ms | 1.01 s | 2 |

Honest findings:

- **Preemptive scheduling** does not help on this workload — every handler returns in microseconds, so forced yield-checks are pure overhead. For workloads with handler-duration variance, it is the biggest single p99 lever available.
- **OPcache + JIT** gives a small gain on this synthetic workload. The same config gives 10–30% on workloads with real CPU-bound computation (JSON serialization of large payloads, template rendering, hash computation). It is also the correct production config regardless of measured gain.

## Benchmark methodology

```bash
# Warm up first — JIT traces need ~5s of traffic to compile.
wrk -t4 -c50 -d5s http://localhost:8080/hello/load

# Then measure.
wrk -t8 -c100 -d15s http://localhost:8080/hello/load
```

Look at all five numbers, not just RPS:

| Metric | Why it matters |
|---|---|
| Req/Sec | Headline throughput. Easy to manipulate; trust it least. |
| Avg latency | What 50% of users see. Useful but smooths over outliers. |
| Stddev | The shape of the distribution. Tight stddev means predictable. |
| Max | Worst single request in the window. Catches GC pauses. |
| Timeouts | Socket-timeout count. Catches kernel-level drops. |

## See also

- [Swoole server settings reference](https://wiki.swoole.com/en/#/server/setting) — authoritative reference for `withSwooleSetting()` options
- [Kernel tuning (sysctls & ulimits)](./sysctls.md) — full reference for all four sysctls and ulimit settings
- [Deployment](./deployment.md) — OPcache, health checks, and pre-flight checklist
- [HTTP servers](../http/servers.md) — worker mode vs thread mode tradeoffs
- [Scaling overview](../scaling/overview.md) — multi-core worker pool architecture
