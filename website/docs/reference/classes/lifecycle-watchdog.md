---
title: LifecycleWatchdog
sidebar_position: 29
related:
  - packages/messenger
  - reference/classes/actor-system
---

# LifecycleWatchdog

Worker-recycling actor that triggers a graceful `ActorSystem::shutdown()` when any `LifecycleThresholds` limit is reached — memory budget, uptime ceiling, or cumulative message count.

## What it does

Long-running PHP processes leak memory over time. The standard defence is to exit cleanly after N messages, X bytes, or T seconds and let the process manager (systemd, supervisor, Kubernetes) restart. `LifecycleWatchdog` replaces `messenger:consume --limit/--memory-limit/--time-limit` flags with a plain supervised actor — no `symfony/console` required.

The watchdog self-ticks on a fixed `$checkInterval` (default 5 s). On each tick it evaluates three thresholds against current state:

| Threshold | Source | Comparison |
|---|---|---|
| `memoryLimitBytes` | `memory_get_usage(true)` (or custom `$memoryProbe`) | `>=` limit |
| `messageLimit` | Cumulative count received via `MessagesProcessed` | `>=` limit |
| `timeLimit` | Elapsed seconds since actor startup | `>=` limit |

A `null` threshold is disabled. All comparisons are inclusive: reaching the limit exactly triggers a breach.

**On breach:**
1. Logs an info message with the breach reason.
2. Dispatches a `WorkerRecyclingTriggered($reason)` PSR-14 event via the actor system's event dispatcher.
3. Increments the `nexus.messenger.worker.recycles` counter (`{recycle}`).
4. Spawns a task to call `$system->shutdown($shutdownTimeout)` (default 10 s).
5. Stops itself (`BehaviorWithState::stopped()`).

**LifecycleWatchdog + ReceiverActor integration:** pass the watchdog ref as the `$processedListener` parameter of `ReceiverActor::create()`. The receiver sends a `MessagesProcessed($count)` report after each busy tick; the watchdog accumulates the counts into its stateful message total.

## Factory

```php
use Monadial\Nexus\Messenger\Lifecycle\LifecycleWatchdog;

LifecycleWatchdog::create(
    system: ActorSystem $system,
    thresholds: LifecycleThresholds $thresholds,
    checkInterval: ?Duration $checkInterval = null,
    shutdownTimeout: ?Duration $shutdownTimeout = null,
    memoryProbe: ?Closure $memoryProbe = null,
): Behavior
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$system` | `ActorSystem` | — | The system to shut down when a threshold is breached. |
| `$thresholds` | `LifecycleThresholds` | — | The limits to evaluate on each tick. |
| `$checkInterval` | `?Duration` | `Duration::seconds(5)` | How often the watchdog evaluates thresholds. |
| `$shutdownTimeout` | `?Duration` | `Duration::seconds(10)` | Deadline passed to `$system->shutdown()`. |
| `$memoryProbe` | `?Closure(): int` | `memory_get_usage(true)` | Override the memory measurement. Return bytes. |

## LifecycleThresholds

`LifecycleThresholds` is an immutable value object. Build it via `none()` and chain wither methods:

```php
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Runtime\Duration;

// All disabled
LifecycleThresholds::none();

// Memory only
LifecycleThresholds::none()->withMemoryLimit(128 * 1024 * 1024); // 128 MiB

// Combined
LifecycleThresholds::none()
    ->withMessageLimit(10_000)
    ->withMemoryLimit(256 * 1024 * 1024)
    ->withTimeLimit(Duration::seconds(3600));
```

| Method | Parameter | Description |
|---|---|---|
| `LifecycleThresholds::none()` | — | Create a thresholds object with all limits disabled. |
| `withMemoryLimit(int $bytes)` | Bytes | Trigger when `memory_get_usage(true) >= $bytes`. |
| `withMessageLimit(int $count)` | Count | Trigger when cumulative processed messages `>= $count`. |
| `withTimeLimit(Duration $limit)` | Duration | Trigger when uptime `>=` the limit (second precision). |

Public properties (readonly): `$memoryLimitBytes: ?int`, `$messageLimit: ?int`, `$timeLimit: ?Duration`.

## Example

```php title="src/bootstrap.php"
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Runtime\Duration;

$watchdogRef = $system->spawn(
    MessengerBridge::watchdogProps(
        system: $system,
        thresholds: LifecycleThresholds::none()
            ->withMessageLimit(10_000)
            ->withMemoryLimit(128 * 1024 * 1024),
        checkInterval: Duration::seconds(5),
        shutdownTimeout: Duration::seconds(10),
    ),
    'watchdog',
);

// Wire the watchdog as the processedListener so it accumulates message counts
$system->spawn(
    MessengerBridge::receiverProps(
        receiver: $transport,
        router: $router,
        processedListener: $watchdogRef,
    ),
    'orders-receiver',
);
```

## Full API reference

[LifecycleWatchdog](https://api.nexusactors.com/classes/Monadial-Nexus-Messenger-Lifecycle-LifecycleWatchdog.html) ·
[LifecycleThresholds](https://api.nexusactors.com/classes/Monadial-Nexus-Messenger-Lifecycle-LifecycleThresholds.html)

## See also

- [nexus-messenger package](../../packages/messenger) — bridge overview and full wiring guide
- [ActorSystem](actor-system) — `shutdown()` method called on threshold breach
- [Messenger bridge guide](../../guides/messenger-bridge) — worker recycling section
- [Config — LifecycleThresholds](../config.md#lifecyclethresholds) — threshold parameter reference
- [ReceiverActor](receiver-actor) — sends `MessagesProcessed` reports to this watchdog
