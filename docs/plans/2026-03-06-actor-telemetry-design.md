# Actor System Telemetry — Design

**Date:** 2026-03-06
**Branch:** feat/symfony-integration

## Goal

Add a pull-based observability layer to Nexus that exposes actor hierarchy snapshots and Swoole
runtime statistics via HTTP endpoints (`/status` JSON and `/metrics` Prometheus text format).
Works in both standalone `SwooleRuntime` and multi-threaded `WorkerPool` contexts with no overhead
until scraped (standalone) or minimal periodic write overhead (WorkerPool).

---

## Core Data Model

### `ActorSnapshot` (recursive tree node, `nexus-core`)

```php
readonly class ActorSnapshot {
    public string $path;
    public bool $alive;
    public int $mailboxDepth;
    public int $mailboxCapacity;
    public bool $mailboxBounded;
    /** @var ActorSnapshot[] */
    public array $children;
}
```

Mirrors the real actor hierarchy. Children are populated recursively from `ActorCell`'s children map.

### `ActorSystemSnapshot` (`nexus-core`)

```php
readonly class ActorSystemSnapshot {
    public string $systemName;
    public string $writerId;
    public bool $isRunning;
    /** @var ActorSnapshot[] */
    public array $actors;       // full recursive tree of top-level actors
    public int $deadLettersCount;
}
```

### `SwooleRuntimeSnapshot` (`nexus-runtime-swoole`)

```php
readonly class SwooleRuntimeSnapshot {
    public int $coroutineNum;
    public int $coroutineRunning;
    public int $coroutineSleeping;
    public int $coroutinePeak;
    public int $activeTimers;
    public int $memoryBytes;
    public int $memoryPeakBytes;
}
```

Populated from `Swoole\Coroutine::stats()` and `memory_get_usage()`/`memory_get_peak_usage()`.

---

## Architecture & Package Placement

### `nexus-core` — snapshot value objects + collection entry points

**New files:**
- `Actor/Telemetry/ActorSnapshot.php`
- `Actor/Telemetry/ActorSystemSnapshot.php`

**Changes to existing classes:**
- `ActorSystem` — stores `ActorCell[]` alongside `ActorRef[]` for top-level actors; gains `snapshot(): ActorSystemSnapshot`
- `ActorCell` — gains `snapshot(): ActorSnapshot` (reads mailbox depth, recurses children)

`ActorSystem` remains clean: no background coroutines, no sinks, no monitoring concerns.

### `nexus-runtime-swoole` — Swoole stats + standalone HTTP server

**New files:**
- `Telemetry/SwooleRuntimeSnapshot.php`
- `Telemetry/TelemetryServer.php`

**Changes to existing classes:**
- `SwooleRuntime` — gains `snapshot(): SwooleRuntimeSnapshot`

`TelemetryServer` is a `Swoole\Coroutine\Http\Server` started in the same coroutine context.
In standalone mode (no WorkerPool), it calls `system->snapshot()` and `runtime->snapshot()`
directly on each request — true pull, zero background overhead.

### `nexus-worker-pool-swoole` — cross-thread aggregation

**New files:**
- `Telemetry/WorkerPoolSnapshot.php`
- `Telemetry/WorkerPoolTelemetryServer.php`

**Changes to existing classes:**
- `WorkerNode` — gains `startTelemetry(Thread\Map $map, Duration $interval): void`

---

## WorkerPool Aggregation via Thread\Map

### Write side (per worker thread)

`WorkerNode::startTelemetry()` spawns a background coroutine in the worker's own runtime:

```
loop:
    snapshot        = system->snapshot()
    runtimeSnapshot = runtime->snapshot()
    map["worker-{workerId}"] = json_encode([
        "system"  => snapshot,
        "runtime" => runtimeSnapshot,
    ])
    sleep($interval)   // default: 5 seconds
```

Also writes once immediately on startup so the first scrape is never empty.

`WorkerRunnable` calls `$node->startTelemetry($sharedMap, Duration::seconds(5))` after
`$configure($node)` / `$handler->onWorkerStart($node)`.

The shared `Thread\Map` is the same one already passed to `WorkerRunnable` for the actor
directory (`ThreadMapDirectory`). Telemetry keys are namespaced with `"telemetry:worker-{id}"`
to avoid collision with actor directory keys.

### Read side (aggregator)

`WorkerPoolTelemetryServer` holds a reference to the same shared `Thread\Map`. On each HTTP
request:

1. Reads all `"telemetry:worker-*"` keys
2. Decodes each worker's `ActorSystemSnapshot` + `SwooleRuntimeSnapshot`
3. Builds a `WorkerPoolSnapshot`: array of per-worker data + summed aggregates (total
   coroutines, total timers, total memory across all workers)
4. Serves JSON (`/status`) or Prometheus text (`/metrics`)

`WorkerPoolTelemetryServer` is started in the main thread (before `Thread\Pool::start()`),
listening on a separate port (default: 9502).

**Staleness:** data is at most N seconds old (configurable interval). Acceptable for
monitoring/debugging tools.

---

## HTTP Endpoints

Both `TelemetryServer` (standalone) and `WorkerPoolTelemetryServer` (pool) expose the same
endpoint contract.

### `GET /status` — JSON

```json
{
  "mode": "worker-pool",
  "workers": [
    {
      "worker_id": 0,
      "system": {
        "name": "nexus-0",
        "writer_id": "01HXYZ...",
        "is_running": true,
        "dead_letters_count": 0,
        "actors": [
          {
            "path": "/user/orders",
            "alive": true,
            "mailbox_depth": 3,
            "mailbox_capacity": 1000,
            "mailbox_bounded": true,
            "children": [
              { "path": "/user/orders/processor", "alive": true, ... }
            ]
          }
        ]
      },
      "runtime": {
        "coroutine_num": 12,
        "coroutine_running": 1,
        "coroutine_sleeping": 11,
        "coroutine_peak": 20,
        "active_timers": 4,
        "memory_bytes": 8388608,
        "memory_peak_bytes": 12582912
      }
    }
  ],
  "aggregates": {
    "total_coroutines": 48,
    "total_timers": 16,
    "total_memory_bytes": 33554432
  }
}
```

Standalone mode: same structure, `"mode": "standalone"`, no `"workers"` array — data is at the
top level.

### `GET /metrics` — Prometheus text format

```
# HELP nexus_actor_mailbox_depth Current number of messages in actor mailbox
# TYPE nexus_actor_mailbox_depth gauge
nexus_actor_mailbox_depth{system="nexus-0",actor="/user/orders",worker="0"} 3

# HELP nexus_actor_alive Whether the actor is alive (1=yes, 0=no)
# TYPE nexus_actor_alive gauge
nexus_actor_alive{system="nexus-0",actor="/user/orders",worker="0"} 1

# HELP nexus_coroutine_num Current number of coroutines
# TYPE nexus_coroutine_num gauge
nexus_coroutine_num{system="nexus-0",worker="0"} 12

# HELP nexus_memory_bytes Current memory usage in bytes
# TYPE nexus_memory_bytes gauge
nexus_memory_bytes{system="nexus-0",worker="0"} 8388608
```

Prometheus metrics are emitted recursively for the full actor tree (flattened with path label).

---

## Changes to Existing Code — Summary

| File | Change |
|------|--------|
| `ActorSystem` | Store `ActorCell[]` for top-level actors; add `snapshot(): ActorSystemSnapshot` |
| `ActorCell` | Add `snapshot(): ActorSnapshot` (reads mailbox stats, recurses children) |
| `SwooleRuntime` | Add `snapshot(): SwooleRuntimeSnapshot` |
| `WorkerNode` | Add `startTelemetry(Thread\Map $map, Duration $interval): void` |
| `WorkerRunnable` | Call `$node->startTelemetry(...)` after handler setup |

### New files

**`nexus-core`:**
- `Actor/Telemetry/ActorSnapshot.php`
- `Actor/Telemetry/ActorSystemSnapshot.php`

**`nexus-runtime-swoole`:**
- `Telemetry/SwooleRuntimeSnapshot.php`
- `Telemetry/TelemetryServer.php`

**`nexus-worker-pool-swoole`:**
- `Telemetry/WorkerPoolSnapshot.php`
- `Telemetry/WorkerPoolTelemetryServer.php`

---

## Design Principles Applied

- **Zero overhead in standalone mode** — `snapshot()` is a pure read; no background coroutines
  or sinks added to `ActorSystem`.
- **ActorSystem stays clean** — no telemetry concerns leak into the core class.
- **Follows existing Thread\Map pattern** — `WorkerNode::startTelemetry()` reuses the same
  shared `Thread\Map` already passed to `WorkerRunnable`, namespaced to avoid collisions.
- **YAGNI** — no `MetricsActor` or user-defined counters in this iteration; add separately if
  needed.
- **No new packages** — all new code fits in the three existing packages that own the relevant
  concerns.

---

## Success Criteria

1. `ActorSystem::snapshot()` returns full recursive actor tree with mailbox stats.
2. `SwooleRuntime::snapshot()` returns live Swoole coroutine and memory stats.
3. Standalone `TelemetryServer` serves correct `/status` JSON and `/metrics` Prometheus text.
4. WorkerPool: each worker writes stats to `Thread\Map`; `WorkerPoolTelemetryServer` aggregates
   all workers into a single endpoint response.
5. Actor hierarchy in `/status` reflects actual parent/child relationships (not a flat list).
6. All existing tests pass; new unit tests cover snapshot value objects and collection logic.
