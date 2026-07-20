---
title: nexus-messenger
related:
  - packages/core
  - packages/messenger-console
  - packages/serialization
  - packages/cluster
  - guides/messenger-bridge
---

# nexus-messenger

Two-way bridge between Nexus actors and standalone Symfony Messenger transports — publish from actors to any broker Messenger supports (AMQP, Redis, Doctrine, …) and consume broker messages into actor mailboxes, without hand-rolled transport code.

## What's in this package

- `MessengerActorRef<T>` — location-transparent `ActorRef` backed by a Messenger `SenderInterface`; `tell()` publishes to the transport; `ask()` is enabled when an `AskSupport` is configured (throws `UnsupportedOperationException` without one)
- `MessengerGateway` — explicit `publish()` egress service over the same sender
- `ReceiverActor` — supervised poll→route→ack loop, one per Messenger `ReceiverInterface`; handles ask envelopes when a `ReplySenderLocator` is configured
- `ReceiverActorConfig` — poll interval, unroutable policy, and ask pending timeout (default 30 s)
- `MessageRouter` — pluggable inbound routing; `MapMessageRouter` (message class → ref) is the default, `StampMessageRouter` (target-path stamp → ref) is the cluster seam
- `NexusMessengerSerializer` — Messenger `SerializerInterface` backed by a Nexus `MessageSerializer`
- `LifecycleWatchdog` + `LifecycleThresholds` — worker recycling via graceful shutdown on memory/uptime/message-count limits
- `MessengerBridge` — static wiring facade (`producer()`, `gateway()`, `receiverProps()`, `spawnReceivers()`, `watchdogProps()`, `askSupport()`)
- `AskSupport` — orchestrates broker ask/reply: lazy reply channel lifecycle, `PendingAskRegistry`, and timeout scheduling; wired via `MessengerBridge::askSupport()`
- `PendingAskRegistry` — first-reply-wins registry for pending ask futures (default capacity: 10 000)
- `ReplyQueueLifecycle` — enum: `Ephemeral` (broker TTL/auto-delete), `DeleteOnShutdown` (best-effort teardown on close), `Persistent` (pre-provisioned; single consumer per queue)
- `TransportReplyChannelFactory` — builds reply channels from a DSN template via a Messenger transport factory; supports `{instance}` and `{name}` placeholders
- `MapReplySenderLocator` — static map from logical channel name to `SenderInterface`; SSRF-safe: wire values resolve via this map only, never as DSNs
- `MessengerReplyRef` — reply-only `ActorRef` that publishes the reply to the configured sender and fires the process-ack; injected as `$ctx->sender()` by `ReceiverActor`
- `CorrelationIdStamp` / `ReplyToStamp` — outbound ask stamps; travel as `X-Nexus-Correlation-Id` and `X-Nexus-Reply-To` headers on the wire
- `SourceActorPathStamp` / `TargetActorPathStamp` / `TraceContextStamp` — provenance, routing, and trace-context stamps
- `UnsupportedOperationException` — thrown by `MessengerActorRef::ask()` when no `AskSupport` is configured, and by `MessengerReplyRef::ask()` (reply refs are send-only)

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

Messages sent through `MessengerActorRef::tell()` must carry `#[MessageType]` (enforced by the nexus-psalm plugin). For broker request/reply, see the [Request/reply (ask)](#requestreply-ask) section below.

## Request/reply (ask)

`MessengerActorRef::ask()` enables broker request/reply when wired with an `AskSupport` instance. Without it the call throws `UnsupportedOperationException`.

```php
// 1. Build a reply channel factory (once per process)
use Monadial\Nexus\Messenger\Ask\ReplyQueueLifecycle;
use Monadial\Nexus\Messenger\Ask\TransportReplyChannelFactory;
use Monadial\Nexus\Messenger\MessengerBridge;

$channelFactory = new TransportReplyChannelFactory(
    $amqpFactory,
    $serializer,
    'amqp://broker/replies-{name}-{instance}?queue[ttl]=60000',
    'orders-replies',
    ReplyQueueLifecycle::Ephemeral,
);

// 2. Wire AskSupport and the producer
$askSupport = MessengerBridge::askSupport($system, $channelFactory);
$ref = MessengerBridge::producer($requestTransport, 'orders-out', askSupport: $askSupport);

// 3. On the responder side — pass a ReplySenderLocator to receiverProps()
use Monadial\Nexus\Messenger\Ask\MapReplySenderLocator;

$system->spawn(
    MessengerBridge::receiverProps(
        $transport,
        $router,
        replySenders: new MapReplySenderLocator(['orders-replies' => $replyTransport]),
    ),
    'orders-receiver',
);

// 4. Ask — must be called inside a fiber
$reply = $ref->ask(new Ping('hello'), Duration::seconds(5))->await();
```

### Reply channel lifecycle

| Lifecycle | Queue created by Nexus | Torn down by Nexus | Notes |
|---|---|---|---|
| `Ephemeral` | Yes — on first ask | No — left to broker TTL / auto-delete | Default. Requires the broker to support per-queue TTL or auto-delete. |
| `DeleteOnShutdown` | Yes — on first ask | Best-effort on `AskSupport::close()` | `close()` calls `reset()` on the transport if available; broker-side TTL remains the authoritative backstop for crashed processes. |
| `Persistent` | No — pre-provisioned | Never | For SQS and brokers where queue creation is slow or costly. **Single consumer per channel name**: all instances sharing the queue compete for replies. Do not use `{instance}` in the DSN template. |

:::tip SQS and slow queue creation
On AWS SQS, prefer `Persistent`: queue creation is an asynchronous API call that takes several seconds and incurs per-queue costs. Pre-provision the reply queue and reference it by name in the DSN.
:::

### Ask semantics

- **At-least-once publish.** Both request and reply travel over a broker transport; either can be redelivered.
- **First-reply-wins.** The `PendingAskRegistry` resolves the future on the first matching reply; duplicate or late replies are acked and dropped silently.
- **Timeout does not cancel remote work.** When the deadline passes, `AskTimeoutException` fails the future — but the responder continues and will publish a reply that is simply dropped. Build idempotent responders.
- **Capacity fail-fast.** When the registry is at `maxPending` (default 10 000), `AskCapacityExceededException` is thrown before any message is sent. Shed load at the call site.
- **Process-ack.** `ReceiverActor` holds the broker envelope un-acked until the responder calls `$ctx->sender()->tell($reply)`. If the responder does not reply within `askPendingTimeout` (default 30 s), the envelope is rejected for redelivery.

## Consumer — broker → actor

```php
$system->spawn(MessengerBridge::receiverProps(
    $transport,
    new MapMessageRouter(Route::to(OrderPlaced::class, $ordersActor)),
), 'orders-receiver');
```

Delivery to the actor mailbox is at-least-once: the receiver acks only after the target mailbox accepts the message. A full (backpressured) mailbox pauses broker consumption — no ack, the broker redelivers. Note that the ack confirms mailbox acceptance, not processing completion: a message accepted into a mailbox can still be lost if the process crashes before the actor handles it. Unroutable messages are rejected by default; configure `UnroutablePolicy::DeadLetters` to forward them to `$system->deadLetters()` instead.

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

Every receiver acks only what the target mailbox accepted, so all three levers preserve at-least-once delivery to the mailbox (acks confirm enqueue, not processing completion).

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
| `nexus.messenger.asks.sent` | Producer (ask) | Incremented when an ask request is published to the transport |
| `nexus.messenger.asks.resolved` | Reply consumer | Incremented when an ask future is resolved with a matching reply |
| `nexus.messenger.asks.timed_out` | Producer (ask) | Incremented when an ask expires without receiving a reply |
| `nexus.messenger.asks.capacity_rejected` | Producer (ask) | Incremented when an ask is rejected because the pending registry is at capacity |
| `nexus.messenger.asks.pending` | Reply consumer | **Gauge** — current number of in-flight asks awaiting a reply |
| `nexus.messenger.asks.unroutable_reply_to` | Consumer | Incremented when an ask envelope is rejected because the reply-to channel is not in the `ReplySenderLocator` |
| `nexus.messenger.asks.responder_expired` | Consumer | Incremented when a pending ask envelope is rejected for redelivery because the responder did not reply within `askPendingTimeout` |
| `nexus.messenger.replies.sent` | Consumer (responder) | Incremented when a reply is published back to the requester transport |
| `nexus.messenger.replies.dropped` | Reply consumer | Incremented when a reply envelope is dropped — missing `CorrelationIdStamp` or unknown correlation ID |

All counters carry a `nexus.message.type` attribute with the message class name, except `nexus.messenger.asks.pending` (gauge), `nexus.messenger.asks.timed_out`, and `nexus.messenger.asks.responder_expired` (correlation IDs are high-cardinality and excluded).

### Spans

| Span | Kind | Key attributes |
|------|------|----------------|
| `messenger.send` | Producer | `messaging.system`, `nexus.message.type`, `nexus.messenger.sender` |
| `messenger.receive` | Consumer | `messaging.system`, `nexus.message.type`, `nexus.messenger.outcome` |

`nexus.messenger.outcome` values: `acked`, `ask_already_pending`, `ask_non_local`, `ask_pending`, `backpressured`, `dead_lettered`, `dropped`, `rejected`, `reply_to_rejected`.

### PSR-14 events

All events live in `Monadial\Nexus\Messenger\Event`.

| Event class | Dispatched when | Properties |
|-------------|-----------------|------------|
| `MessagePublished` | After a successful `tell()` / `publish()` | `$message`, `$senderName` |
| `MessageConsumed` | After the broker is acked for a routed message | `$message`, `$targetPath` |
| `MessageRejected` | An unroutable message is rejected | `$message` |
| `MessageDeadLettered` | An unroutable message goes to dead letters | `$message` |
| `WorkerRecyclingTriggered` | Just before watchdog shuts the system down | `$reason` |
| `AskStarted` | After an ask request is published to the transport | `$message`, `$correlationId` |
| `AskResolved` | After a reply arrives and the ask future is resolved | `$correlationId`, `$reply` |
| `AskTimedOut` | After an ask deadline passes without a reply | `$correlationId` |
| `ReplyPublished` | After a responder publishes a reply back to the transport | `$message`, `$correlationId` |

## See also

- [Messenger bridge guide](/docs/guides/messenger-bridge)
- [Standalone integration](/docs/guides/standalone-integration)
- [Redis example app](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-messenger-redis) — competing consumers, worker recycling, and PSR-14 events end-to-end
