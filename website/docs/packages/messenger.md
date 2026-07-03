---
title: nexus-messenger
related:
  - packages/core
  - packages/serialization
  - packages/cluster
  - guides/messenger-bridge
---

# nexus-messenger

Two-way bridge between Nexus actors and standalone Symfony Messenger transports — publish from actors to any broker Messenger supports (AMQP, Redis, Doctrine, …) and consume broker messages into actor mailboxes, without hand-rolled transport code.

## What's in this package

- `MessengerActorRef<T>` — location-transparent `ActorRef` backed by a Messenger `SenderInterface`; `tell()` publishes to the transport
- `MessengerGateway` — explicit `publish()` egress service over the same sender
- `ReceiverActor` — supervised poll→route→ack loop, one per Messenger `ReceiverInterface`
- `ReceiverActorConfig` — poll interval and unroutable policy (reject vs dead letters)
- `MessageRouter` — pluggable inbound routing; `MapMessageRouter` (message class → ref) is the default, `StampMessageRouter` (target-path stamp → ref) is the cluster seam
- `NexusMessengerSerializer` — Messenger `SerializerInterface` backed by a Nexus `MessageSerializer`
- `LifecycleWatchdog` + `LifecycleThresholds` — worker recycling via graceful shutdown on memory/uptime/message-count limits
- `MessengerBridge` — static wiring facade (`producer()`, `gateway()`, `receiverProps()`, `spawnReceivers()`, `watchdogProps()`)
- `SourceActorPathStamp` / `TargetActorPathStamp` / `TraceContextStamp` — provenance, routing, and trace-context stamps
- `UnsupportedOperationException` — thrown by `MessengerActorRef::ask()` (broker request/reply is deferred beyond v1)

## Install

```bash
composer require nexus-actors/messenger
```

Depends on `nexus-actors/core`, `nexus-actors/runtime`, `nexus-actors/serialization`, `nexus-actors/observability`, and `symfony/messenger` only — never `framework-bundle` or `symfony/console`.

## Producer — actor → broker

```php
$orders = MessengerBridge::producer($transport, 'orders-out');
$orders->tell(new OrderPlaced('A-42')); // identical to a local tell()

MessengerBridge::gateway($transport)->publish(new OrderPlaced('A-42'));
```

Messages sent through `MessengerActorRef::tell()` must carry `#[MessageType]` (enforced by the nexus-psalm plugin). `ask()` throws `UnsupportedOperationException` in v1.

## Consumer — broker → actor

```php
$system->spawn(MessengerBridge::receiverProps(
    $transport,
    new MapMessageRouter([OrderPlaced::class => $ordersActor]),
), 'orders-receiver');
```

Delivery is at-least-once: the receiver acks only after the target mailbox accepts the message. A full (backpressured) mailbox pauses broker consumption — no ack, the broker redelivers. Unroutable messages are rejected by default; configure `UnroutablePolicy::DeadLetters` to forward them to `$system->deadLetters()` instead.

## Worker recycling

```php
$system->spawn(MessengerBridge::watchdogProps(
    $system,
    LifecycleThresholds::none()
        ->withMemoryLimit(256 * 1024 * 1024)
        ->withTimeLimit(Duration::seconds(3600))
        ->withMessageLimit(10_000),
), 'watchdog');
```

Replaces `messenger:consume --limit/--memory-limit/--time-limit`: when a threshold is reached the watchdog triggers a graceful `ActorSystem::shutdown()`, and your process manager (systemd, supervisor, k8s) restarts the worker.

## Scaling out

Three independent levers, combinable:

```php
// 1. N competing consumers in one process (best on Swoole coroutine transports)
MessengerBridge::spawnReceivers($system, 10, 'receiver', $transport, $router);
```

```bash
# 2. N worker processes — the broker load-balances between them; the
#    LifecycleWatchdog + your process manager handle recycling
systemctl start nexus-worker@{1..10}
```

3. Handler-side parallelism: route to a pool of target actors — see the [routing patterns guide](/docs/guides/routing-patterns).

Every receiver acks only what the target mailbox accepted, so all three levers preserve at-least-once semantics.

## Host models

- **Nexus owns the process** — boot an `ActorSystem` with receiver actor(s) plus the watchdog and call `run()`. Recommended for standalone queue workers.
- **Messenger owns the process** — an existing Symfony app keeps running `messenger:consume`; the bridge contributes routing and serialization, and actors run in-process.

## Observability

Pass an `Observability` instance (from `nexus-observability-otel`) and a PSR-14 `EventDispatcherInterface` to unlock full telemetry. All telemetry is fire-and-forget — errors are always swallowed and never break message flow.

### Metrics

| Metric | Side | Description |
|--------|------|-------------|
| `nexus.messenger.messages.sent` | Producer | Incremented on every successful `tell()` / `publish()` |
| `nexus.messenger.messages.consumed` | Consumer | Incremented after the actor mailbox accepts the envelope and acks the broker |
| `nexus.messenger.messages.rejected` | Consumer | Incremented when an unroutable message is rejected back to the transport |
| `nexus.messenger.messages.dead_lettered` | Consumer | Incremented when an unroutable message is forwarded to dead letters |
| `nexus.messenger.enqueue.backpressured` | Consumer | Incremented each time a full mailbox causes a poll pause |
| `nexus.messenger.enqueue.dropped` | Consumer | Incremented when the target mailbox is closed or drops the message; it stays un-acked for redelivery |
| `nexus.messenger.worker.recycles` | Watchdog | Incremented when a threshold breach triggers graceful shutdown |

All counters carry a `nexus.message.type` attribute with the message class name.

### Spans

| Span | Kind | Key attributes |
|------|------|----------------|
| `messenger.send` | Producer | `messaging.system`, `nexus.message.type`, `nexus.messenger.sender` |
| `messenger.receive` | Consumer | `messaging.system`, `nexus.message.type`, `nexus.messenger.outcome` |

`nexus.messenger.outcome` values: `acked`, `backpressured`, `dead_lettered`, `dropped`, `rejected`.

### PSR-14 events

All events live in `Monadial\Nexus\Messenger\Event`.

| Event class | Dispatched when | Properties |
|-------------|-----------------|------------|
| `MessagePublished` | After a successful `tell()` / `publish()` | `$message`, `$senderName` |
| `MessageConsumed` | After the broker is acked for a routed message | `$message`, `$targetPath` |
| `MessageRejected` | An unroutable message is rejected | `$message` |
| `MessageDeadLettered` | An unroutable message goes to dead letters | `$message` |
| `WorkerRecyclingTriggered` | Just before watchdog shuts the system down | `$reason` |

## See also

- [Messenger bridge guide](/docs/guides/messenger-bridge)
- [Standalone integration](/docs/guides/standalone-integration)
- [Redis example app](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-messenger-redis) — competing consumers, worker recycling, and PSR-14 events end-to-end
