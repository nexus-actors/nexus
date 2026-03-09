# Nexus Symfony integration

`monadial/nexus-symfony` integrates the Nexus actor system with Symfony, replacing the standard PHP-FPM/Nginx runtime with a Swoole HTTP server. Every Swoole worker runs a full `ActorSystem` alongside a pool of Symfony kernels, enabling concurrent request handling within a single OS process — no fork, no restart between requests.

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.5+ (ZTS build for Swoole threads) |
| Swoole | 6.0+ (`--enable-swoole-thread`) |
| Symfony | 7.x |
| `monadial/nexus-core` | `^0.x` |
| `monadial/nexus-runtime-swoole` | `^0.x` |

## Contents

| Document | Description |
|---|---|
| [getting-started.md](getting-started.md) | Installation, bundle registration, runtime configuration, first run |
| [kernel-pool.md](kernel-pool.md) | How `KernelPoolActor` and `KernelActor` manage concurrency, backpressure, and crash recovery |
| [actors-in-symfony.md](actors-in-symfony.md) | Declaring actors with `#[Actor]`, injecting `ActorRef`, `ask()` vs `tell()` from controllers |
| [coroutine-scoped-services.md](coroutine-scoped-services.md) | Per-request isolation under Swoole using `#[CoroutineScoped]` |
| [observability.md](observability.md) | Request ID propagation, Monolog processor, tracing integration |
| [performance.md](performance.md) | Benchmark results, tuning workers and kernel pool size, MySQL tips |

## Architecture at a glance

```
                          ┌─────────────────────────────────────────┐
                          │  Swoole HTTP Server (NexusRunner)        │
                          │                                          │
  HTTP request ──────────►│  Worker 0          Worker 1  …  Worker N │
                          │  ┌─────────────┐  ┌───────────────────┐ │
                          │  │ ActorSystem │  │    ActorSystem    │ │
                          │  │  kernel-0   │  │    kernel-0       │ │
                          │  │  kernel-1   │  │    kernel-1       │ │
                          │  │  …          │  │    …              │ │
                          │  │  kernel-K   │  │    kernel-K       │ │
                          │  │  [catalog]  │  │    [catalog]      │ │
                          │  │  [inventory]│  │    [inventory]    │ │
                          │  └─────────────┘  └───────────────────┘ │
                          └─────────────────────────────────────────┘
```

Each worker has its own `ActorSystem`. The kernel pool (`KernelPoolActor`) distributes incoming requests to idle `KernelActor` children. Isolated actors (tagged with `#[Actor]`) are spawned once per worker and remain alive across all requests.
