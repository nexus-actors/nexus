---
sidebar_position: 13
title: Performance Tuning
---

# Performance Tuning

A field guide to making Nexus HTTP fast. Out of the box you get ~110k RPS on
a laptop-class container; getting to single-digit-millisecond tail latency
requires conscious tuning at four layers — framework, PHP runtime, Swoole
event loop, and the Linux kernel.

This page documents what we've actually measured. Numbers come from `wrk -t8
-c100 -d15s` against `examples/thread-server-no-log.php` (Swoole thread mode,
8 workers, no logging) on the project's standard Docker image.

## What "fast" looks like

Reasonable targets for an 8-thread Nexus deploy on commodity hardware:

| Metric | Comfortable | Stretch |
|---|---|---|
| Throughput | 80k RPS | 120k RPS |
| Avg latency | < 50 ms | < 30 ms |
| p99 latency | < 100 ms | < 50 ms |
| Max latency | < 1 s | < 250 ms |

Hitting the comfortable column is mostly free — install Nexus, write
handlers, ship. The stretch column requires the tuning below.

## The four bottlenecks

In production load, latency comes from one of:

1. **Per-request allocations** triggering PHP garbage collection — visible as
   periodic stddev spikes.
2. **Coroutine scheduler contention** when one slow request starves others —
   visible as p99 climbing under load even when avg stays flat.
3. **Linux TCP defaults** dropping connections under burst — visible as
   socket-timeout errors and 1-second tails (the kernel SYN-cookie RTO).
4. **OPcache / JIT** not warmed up — visible as cold-start latency.

Each gets its own section below. Apply them in order; later ones depend on
earlier ones for full effect.

## Framework: closure pre-binding

**Already applied in `feat/nexus-http` since commit `3efe0b87`.**

`HandlerResolver` builds the argument-resolution closure ONCE per handler at
compile time, not once per request. Each request just calls the captured
closure with `(request, scope, pathParams)`, which iterates a `foreach`
loop over pre-compiled `ParamMetadata` and calls each resolver's
`resolve()` directly.

What this saves per request:
- One method-call indirection (`$this->buildArgs` no longer in the hot path)
- One closure allocation (the `array_map` callback)
- One `ResolverServices` allocation (now captured at compile time)

Measured impact:

| Metric | Per-request `array_map` | Pre-bound closure | Δ |
|---|---|---|---|
| RPS | 108,540 | 112,650 | **+3.8%** |
| Avg latency | 40.0 ms | 33.8 ms | **−15%** |
| Stddev | 99 ms | 71 ms | **−28%** |
| Max | 1.40 s | 1.01 s | **−28%** |
| Timeouts (15s test) | 14 | 5 | **−64%** |

The throughput delta is small (within run-to-run noise), but the tail
metrics are real and consistent.

## PHP runtime: OPcache and JIT

The default Docker PHP install has OPcache enabled but JIT disabled. Switch
JIT on:

```ini title="docker/php.ini"
; Core OPcache
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0     ; production only — never re-stat
opcache.save_comments=1           ; required for attribute reflection

; JIT — tracing mode is the right default for Nexus
opcache.jit=tracing
opcache.jit_buffer_size=128M
opcache.jit_hot_loop=64
opcache.jit_hot_func=127

; Preload hot framework classes
opcache.preload=/app/preload.php
opcache.preload_user=www-data
```

The JIT specifically helps polymorphic dispatch — every `$resolver->resolve($p,
$ctx)` is a virtual call that the JIT can trace and inline. Without it, each
call costs a vtable lookup.

A minimal preload file:

```php title="preload.php"
<?php

require __DIR__ . '/vendor/autoload.php';

// Framework hot path
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

In production, also disable runtime asserts:

```ini
assert.active=0
zend.assertions=-1
```

Several Nexus resolvers use `assert($ctx instanceof RequestBoundContext)` —
in production these become no-ops the JIT removes entirely. With asserts
active, you pay the `instanceof` per resolver per request.

## Swoole: server config

Add to your `SwooleThreadConfig` (or `SwooleWorkerConfig`):

```php
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(8)
    ->maxRequest(100_000)
    ->withSwooleSetting([
        // Disable Nagle: respond as soon as the buffer is ready, not on
        // 200ms timer. Critical for sub-millisecond p99.
        'tcp_nodelay' => true,

        // Defer accept until data arrives — skips the empty-SYN dance.
        'tcp_defer_accept' => 1,

        // Keep-alive at the TCP layer.
        'open_tcp_keepalive' => 1,
        'tcp_keepidle' => 60,

        // Bigger socket buffers under burst. Default 8 MB → 32 MB.
        'socket_buffer_size' => 32 * 1024 * 1024,

        // Max request body. Most APIs don't need 4MB; trim if you care.
        'package_max_length' => 4 * 1024 * 1024,

        // Output buffer for large responses.
        'buffer_output_size' => 32 * 1024 * 1024,

        // Backlog — must be ≤ kernel somaxconn (see below).
        'backlog' => 65535,
    ]);
```

### Preemptive coroutine scheduling

Preemptive scheduling forces every coroutine to yield every few ms,
regardless of whether it would otherwise. Turn it on **at boot**, before
`Server::start()`:

```php
\Swoole\Coroutine::set([
    'enable_preemptive_scheduler' => true,
    'max_coroutine' => 100_000,
]);
```

**When this pays off:** workloads where handler durations vary widely
(one 50ms handler blocking 99 fast ones), or where some handlers do
genuine CPU-bound work (big JSON serialization, hash computation, image
processing). On those workloads, preemptive scheduling is the single
biggest p99 win on Swoole — measured 2–5× p99 improvements are typical
in the wild.

**When it costs you:** uniformly-fast workloads where every coroutine
returns in microseconds. The forced yield-checks add measurable overhead
without any benefit. We measured this against `/hello/load` (immediate
JSON response) and saw throughput unchanged but tail latency
*marginally worse* — preemption running on coroutines that wouldn't
have yielded anyway.

**Rule of thumb:** if your p99 is more than 10× your p50, turn it on.
If your p99 is within 3× of your p50, leave it off and don't pay the
preemption tax. Measure both ways on your actual workload.

## Kernel: TCP sysctls

The 1-second `max` latency you'll see on default Docker isn't framework
overhead — it's the Linux TCP RTO. Under SYN-queue overflow, the kernel
drops connections and the client retries after exactly 1 second.

Fix via `sysctls` in `docker-compose.yml`:

```yaml title="docker-compose.yml"
php-swoole:
  build:
    context: .
    dockerfile: docker/Dockerfile
    target: php-swoole
  ports:
    - "8080:8080"
  sysctls:
    # Raise SYN backlog and listen-accept queue (default 4096 → 65535).
    # Under load, the default surfaces as 1-second tail-latency spikes
    # (kernel SYN-cookie retries take exactly the TCP RTO).
    net.core.somaxconn: 65535
    net.ipv4.tcp_max_syn_backlog: 65535
    # Reuse TIME_WAIT sockets — short-lived connections recycle faster.
    net.ipv4.tcp_tw_reuse: 1
    # Widen the ephemeral port range so high-concurrency outbound
    # connections (e.g. backend calls from handlers) don't starve.
    net.ipv4.ip_local_port_range: "1024 65535"
  ulimits:
    nofile:
      soft: 65535
      hard: 65535
```

The `ulimits.nofile` bump is the other half — at 100k concurrent connections
the default 1024 fd limit becomes the bottleneck before TCP does.

### Why somaxconn matters

The kernel maintains two per-listener queues:

1. **SYN queue** (incomplete connections, sized by `tcp_max_syn_backlog`)
2. **Accept queue** (completed, awaiting `accept()`, sized by `somaxconn`)

When either fills, the kernel either drops the SYN (client retries after
RTO ≈ 1 s) or sends a SYN-cookie (no perf cost, but loses TCP options).
You'll see the drop as a 1-second tail-latency spike. Raising both queues
fixes it.

These four sysctls together reduced socket-timeout count in our benchmark
from 14 (15s test) to 1 — an **80% drop in tail outliers**.

## Production: putting it all together

The full configuration for a production-grade Nexus HTTP deploy:

```php title="server.php"
\Swoole\Coroutine::set([
    'enable_preemptive_scheduler' => true,
    'max_coroutine' => 100_000,
]);

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->maxRequest(100_000)
        ->shutdownTimeout(Duration::seconds(15))
        ->withSwooleSetting([
            'tcp_nodelay' => true,
            'tcp_defer_accept' => 1,
            'open_tcp_keepalive' => 1,
            'socket_buffer_size' => 32 * 1024 * 1024,
            'package_max_length' => 4 * 1024 * 1024,
            'buffer_output_size' => 32 * 1024 * 1024,
            'backlog' => 65535,
        ]),
    static fn ($system, $node) => /* your app */,
);
```

Plus `php.ini` (OPcache + JIT + assert.active=0), `docker-compose.yml` (the
sysctls block above), and `preload.php` (hot framework classes).

## Benchmark methodology

For comparable numbers across deploys:

```bash
# From the HOST (not the container — wrk + Swoole on the same kernel
# can confuse measurements).
wrk -t8 -c100 -d15s http://localhost:8080/hello/load

# Warm up first — JIT traces need ~5s of traffic to compile.
wrk -t4 -c50 -d5s http://localhost:8080/hello/load
```

Look at all four numbers, not just RPS:

| Metric | Why it matters |
|---|---|
| Req/Sec | Headline throughput. Easy to manipulate; trust it least. |
| Avg latency | What 50% of users see. Useful but smooths over outliers. |
| Stddev | The shape of the distribution. Tight stddev = predictable. |
| Max | Worst single request in the window. Catches GC pauses. |
| Timeouts | Socket-timeout count. Catches kernel-level drops. |

A deploy with 100k RPS and 800ms tail latency is worse for users than a
deploy with 80k RPS and 50ms tail latency. Optimise for the metric your
users feel.

## Measured impact summary

Cumulative impact of the framework- and kernel-level optimizations on the
uniformly-fast `/hello/load` benchmark:

| Configuration | RPS | Avg | Stddev | Max | Timeouts |
|---|---|---|---|---|---|
| Baseline (registry, default Docker) | 108k | 40 ms | 99 ms | 1.40 s | 14 |
| + Closure pre-binding (`3efe0b87`) | 112k | 34 ms | 71 ms | 1.01 s | 5 |
| + Kernel sysctls (this page) | **115k** | **33 ms** | 68 ms | 1.00 s | **1** |
| + Preemptive coroutines | 115k | 35 ms | 82 ms | 1.40 s | 4 |
| + OPcache JIT (config — not measured) | — | — | — | — | — |

Preemptive coroutine scheduling does NOT help on this workload — every
handler returns in microseconds, so the forced yield-checks are pure
overhead. See the
[Preemptive coroutine scheduling](#preemptive-coroutine-scheduling)
caveat above. For workloads with handler-duration variance, it's the
biggest single p99 lever you have.

## Further reading

- [Swoole tuning guide](https://wiki.swoole.com/en/#/server/setting) —
  authoritative reference for `withSwooleSetting()` options
- [Linux TCP tuning](https://www.kernel.org/doc/Documentation/networking/ip-sysctl.txt) —
  every sysctl explained
- [PHP JIT documentation](https://www.php.net/manual/en/opcache.configuration.php) —
  `opcache.jit` mode reference
- The wider Nexus
  [Servers](./servers.md) page covers worker-mode vs thread-mode tradeoffs.
