---
title: Bridging Symfony Messenger
related:
  - packages/messenger
  - guides/standalone-integration
  - packages/serialization
---

# Bridging Symfony Messenger

`nexus-messenger` connects Nexus actors to Symfony Messenger transports in both directions: actors can publish messages to any broker Messenger supports (AMQP, Redis, Doctrine, SQS, …), and broker messages can flow into actor mailboxes through a supervised poll loop. This guide walks through every aspect of the bridge — from a first publish to a production-grade multi-worker setup with observability.

## When to reach for the bridge

Three situations call for the bridge:

**1. Standalone queue worker.** You want a long-running PHP process that consumes from a broker and routes work to an actor system. Nexus owns the process lifecycle: boot an `ActorSystem`, spawn `ReceiverActor`(s) and a `LifecycleWatchdog`, and call `$system->run()`. This replaces `bin/console messenger:consume` entirely and eliminates the Symfony framework dependency from your worker image.

**2. Embedded in an existing Symfony app.** Your application already runs `messenger:consume`. Adding the bridge wires Nexus routing and serialization into the existing pipeline — actors run in-process alongside the rest of the app. The framework owns the process; the bridge contributes `ReceiverActor` as a regular Messenger handler adapter.

**3. Durable async boundary between services.** A broker is your service boundary: upstream services publish, downstream services consume. `MessengerActorRef` makes a remote topic look like a local actor ref to the producing side, while `NexusMessengerSerializer` ensures the wire format travels with stable `#[MessageType]` identifiers rather than PHP class names.

## Publishing from actors

### MessengerActorRef vs MessengerGateway

The bridge offers two producer APIs. Use `MessengerActorRef` when actor code should not know it is talking to a broker — it looks like any other `ActorRef`:

```php title="src/Order/OrderActor.php"
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Serialization\MessageType;

#[MessageType('order.placed')]
final readonly class OrderPlaced
{
    public function __construct(public string $orderId) {}
}

// Boot once, inject via Props / DI
$ordersOut = MessengerBridge::producer($amqpTransport, 'orders-out');

// Inside any actor handler — identical to a local tell()
$ordersOut->tell(new OrderPlaced('A-42'));
```

Use `MessengerGateway` when you want the call site to make it explicit that the message leaves the system:

```php title="src/Checkout/CheckoutService.php"
use Monadial\Nexus\Messenger\MessengerBridge;

$gateway = MessengerBridge::gateway($amqpTransport);
$gateway->publish(new OrderPlaced('A-42'));
```

Both APIs produce the same wire output. `MessengerGateway::publish()` also accepts extra Messenger stamps as a second argument when you need transport-level routing keys or delay stamps.

### #[MessageType] requirement

Every message class sent through `MessengerActorRef::tell()` must carry `#[MessageType]`. The nexus-psalm plugin enforces this at static-analysis time. The attribute's value becomes the `type` header on the wire, decoupling the receiver from PHP class names:

```php
use Monadial\Nexus\Serialization\MessageType;

#[MessageType('inventory.reserved')]
final readonly class InventoryReserved
{
    public function __construct(
        public string $orderId,
        public int $quantity,
    ) {}
}
```

### ask()

`MessengerActorRef::ask()` supports broker request/reply when wired with an `AskSupport` instance via `MessengerBridge::askSupport()`. Without it, `ask()` throws `UnsupportedOperationException`. See the [Request/reply over the broker](#requestreply-over-the-broker) chapter for the full walkthrough.

## Consuming into actors

### Building the router

`MapMessageRouter` routes by message class — the most common case:

```php title="src/Worker/RouterSetup.php"
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;

$router = new MapMessageRouter([
    OrderPlaced::class    => $ordersActor,
    PaymentReceived::class => $paymentsActor,
]);
```

For cluster seams where the target actor path travels in the message itself, swap in `StampMessageRouter` — it reads the `TargetActorPathStamp` that the producer side stamps on outbound envelopes.

### Spawning the receiver

```php title="src/Worker/Worker.php"
use Monadial\Nexus\Messenger\MessengerBridge;

$system->spawn(
    MessengerBridge::receiverProps($transport, $router),
    'orders-receiver',
);
```

The `ReceiverActor` runs a poll loop: fetch a batch from the transport, route each message to its target actor, ack with the transport only after the mailbox accepted the envelope. The default poll interval is 100 ms.

### Tuning poll behaviour

Override the defaults with `ReceiverActorConfig`:

```php title="src/Worker/Worker.php"
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;
use Monadial\Nexus\Runtime\Duration;

$config = ReceiverActorConfig::default()
    ->withPollInterval(Duration::millis(50))
    ->withUnroutablePolicy(UnroutablePolicy::DeadLetters);

$system->spawn(
    MessengerBridge::receiverProps($transport, $router, $config),
    'orders-receiver',
);
```

### At-least-once semantics

The bridge acks a broker message only after the target actor's mailbox has accepted the envelope (`EnqueueResult::Accepted`). If the mailbox is bounded and full, the poll returns `Backpressured` — the message is not acked, and the broker redelivers it later. Targets that do not implement `BackpressureCapable` receive the message via `tell()` and are acked unconditionally (no backpressure signal). This means:

- Mailbox overflow never silently drops messages.
- Your actor handlers should be idempotent (or deduplicate on a correlation ID) because redelivery is possible.
- Setting a very small bounded mailbox without a backpressure-aware overflow strategy can cause tight redeliver loops — prefer unbounded mailboxes or generous bounds on consumer actors.

### Supervision for broker blips

The `ReceiverActor` is a regular actor under your actor system's supervision tree. Wrap it in a one-for-one supervisor with backoff to survive transient transport failures:

```php title="src/Worker/Worker.php"
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;

$props = MessengerBridge::receiverProps($transport, $router)
    ->withSupervision(
        SupervisionStrategy::oneForOne(
            maxRetries: 5,
            window: Duration::seconds(60),
        ),
    );

$system->spawn($props, 'orders-receiver');
```

## Handling unroutable messages

When the router cannot resolve a message to an actor ref, the `ReceiverActor` applies the configured `UnroutablePolicy`. The default is `Reject`, which tells the transport to reject (and potentially dead-letter at the broker level) the message without acking it:

```php
// Default — reject back to the transport
ReceiverActorConfig::default(); // UnroutablePolicy::Reject
```

Switch to `DeadLetters` to forward unroutable messages to `$system->deadLetters()` and ack them at the transport, preventing redelivery loops:

```php title="src/Worker/Worker.php"
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;

$config = ReceiverActorConfig::default()
    ->withUnroutablePolicy(UnroutablePolicy::DeadLetters);

$system->spawn(
    MessengerBridge::receiverProps($transport, $router, $config, $system->deadLetters()),
    'orders-receiver',
);
```

Choose `DeadLetters` when your broker does not have its own dead-letter queue, or when you want in-process visibility into unroutable messages via the dead-letters actor. Choose `Reject` when the broker already handles dead-lettering and you do not want double-dead-lettering.

## Swapping serialization

`NexusMessengerSerializer` implements Symfony Messenger's `SerializerInterface` and delegates encoding to any Nexus `MessageSerializer`. Encode and decode are asymmetric: encode emits the `#[MessageType]` name when registered and falls back to the FQCN; decode requires the `type` header to be registered in the `TypeRegistry` and throws `MessageDecodingFailedException` if it is not. To deliberately accept a FQCN header on decode, register the class as its own type name: `$registry->register(Foo::class, Foo::class)`. Wire the serializer into a transport that accepts a custom serializer:

```php title="src/Serialization/SerializerSetup.php"
use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;

$registry = new TypeRegistry();
$registry->registerFromAttribute(OrderPlaced::class);
$registry->registerFromAttribute(PaymentReceived::class);

$serializer = new NexusMessengerSerializer(
    new ValinorMessageSerializer($registry),
    $registry,
);

// Pass $serializer to your transport factory
// e.g. new RedisTransport($connection, $serializer)
```

`NexusMessengerSerializer` preserves three bridge stamps across the wire as HTTP-style headers:

| Header | Stamp | Purpose |
|--------|-------|---------|
| `X-Nexus-Source-Path` | `SourceActorPathStamp` | Provenance — which actor sent the message |
| `X-Nexus-Target-Path` | `TargetActorPathStamp` | Routing hint for `StampMessageRouter` |
| `X-Nexus-Trace-Context` | `TraceContextStamp` | W3C trace context for distributed tracing |

**v1 limitation:** other Symfony stamps (e.g. `DelayStamp`, `RedeliveryStamp`) are not preserved. If you need full stamp fidelity or interop with non-Nexus producers (e.g. a Python service), swap in Symfony's built-in `Serializer` instead — the bridge's routing and actor integration still work regardless of which serializer the transport uses.

`NexusMessengerSerializer` always requires a `TypeRegistry`. When both producer and consumer share the same codebase and you want a simpler setup, you can back it with `PhpNativeSerializer` — but always provide an explicit allow-list to prevent PHP object injection: `new PhpNativeSerializer(allowedClasses: [OrderPlaced::class, PaymentReceived::class])`. Use `ValinorMessageSerializer` for cross-service, cross-language, or cross-deployment message exchange.

## Recycling workers

Long-running PHP processes accumulate memory over time and can hold fragmented OPcache. The `LifecycleWatchdog` replaces the `--limit`, `--memory-limit`, and `--time-limit` options of `messenger:consume` with in-process threshold checks that trigger a graceful `ActorSystem::shutdown()`.

```php title="src/Worker/Worker.php"
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Runtime\Duration;

$system->spawn(
    MessengerBridge::watchdogProps(
        $system,
        LifecycleThresholds::none()
            ->withMemoryLimit(256 * 1024 * 1024)   // 256 MB
            ->withTimeLimit(Duration::seconds(3600)) // 1 hour
            ->withMessageLimit(10_000),              // 10 k messages
    ),
    'watchdog',
);

$system->run(); // blocks; watchdog triggers shutdown when a threshold is breached
```

When a threshold is breached, the watchdog calls `ActorSystem::shutdown()` with a grace period (default 10 s), which broadcasts `PoisonPill` to all root actors and waits for clean termination before the process exits. Your process manager (systemd, supervisor, or a k8s liveness probe) then restarts the worker.

All three thresholds are independent and additive — whichever is reached first triggers recycling. Pass only the ones you need; `LifecycleThresholds::none()` with no `with*()` calls disables recycling entirely (not recommended in production).

## Scaling to N workers

Three independent levers are available, and they compose:

### Lever 1: Competing consumers in one process

`MessengerBridge::spawnReceivers()` starts N `ReceiverActor`s, each polling the same transport independently. The actors are named `<prefix>-0` through `<prefix>-{N-1}`:

```php title="src/Worker/Worker.php"
use Monadial\Nexus\Messenger\MessengerBridge;

// 3 concurrent consumers in one process
MessengerBridge::spawnReceivers(
    $system,
    3,
    'receiver',
    $transport,
    $router,
);
```

This is most effective with Swoole coroutine transports where each receiver can do true async I/O. On Fiber-based runtimes the receivers interleave cooperatively.

### Lever 2: N worker processes

Run multiple identical worker processes and let the broker distribute load across them. Because each process acks independently, this is inherently at-least-once:

```ini title="systemd/nexus-worker@.service"
[Unit]
Description=Nexus queue worker %i

[Service]
ExecStart=/usr/bin/php /srv/app/bin/worker.php
Restart=always
RestartSec=5
```

```bash
systemctl enable --now nexus-worker@{1..4}
```

Each worker carries its own `LifecycleWatchdog` — no coordination needed.

### Lever 3: Target actor pools

Route to a pool of handler actors rather than a single ref. The pool distributes work across N actors (and on a worker pool, across N threads). Cross-link: see [routing patterns](/docs/guides/routing-patterns) and [fan-out](/docs/guides/fan-out) for concrete pool patterns.

### All levers preserve at-least-once

Every `ReceiverActor` acks only after the target mailbox returned `Accepted`. Whether you have 1 consumer or 10 across 5 processes, the ack-on-accept rule is upheld — broker redelivery is the safety net for any in-flight message when a process crashes.

## Choosing a host model

| | Nexus owns the process | Messenger owns the process |
|-|------------------------|---------------------------|
| **Process entry point** | `$system->run()` | `bin/console messenger:consume` |
| **Lifecycle control** | `LifecycleWatchdog` | `--limit / --memory-limit / --time-limit` |
| **Framework dependency** | None — pure PHP | Symfony FrameworkBundle |
| **Actor supervision** | Full tree, configurable | Per-handler only |
| **Best for** | Dedicated queue workers, microservices | Symfony apps adding async processing |
| **Scaling** | `spawnReceivers()` + process manager | Multiple `messenger:consume` workers |

Choose **Nexus owns the process** when you are building a new service or want to remove the framework from your worker image. Choose **Messenger owns the process** when you are adding Nexus to an existing Symfony application and do not want to change your deployment topology.

## Observability

The bridge emits spans, metrics, and PSR-14 events out of the box when you wire an `Observability` instance (from `nexus-observability-otel`) and an optional PSR-14 `EventDispatcherInterface`. Telemetry failures are always swallowed — they never affect message delivery.

### Wiring

```php title="src/Worker/Worker.php"
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Observability\Observability;

/** @var Observability $observability  wired from nexus-observability-otel */
/** @var \Psr\EventDispatcher\EventDispatcherInterface $events */

// Producer with telemetry
$orders = MessengerBridge::producer(
    $amqpTransport,
    'orders-out',
    observability: $observability,
    events: $events,
);

// Consumer with telemetry
$system->spawn(
    MessengerBridge::receiverProps(
        $amqpTransport,
        new MapMessageRouter([OrderPlaced::class => $ordersActor]),
        events: $events,
        observability: $observability,
    ),
    'orders-receiver',
);
```

`spawnReceivers()` accepts the same trailing `$events` and `$observability` parameters and passes them to each spawned receiver. When `$events` is `null` on `spawnReceivers()`, it falls back to `$system->eventDispatcher()` automatically.

### Metrics emitted

| Metric | Side |
|--------|------|
| `nexus.messenger.messages.sent` | Producer — every successful `tell()` / `publish()` |
| `nexus.messenger.messages.consumed` | Consumer — after mailbox accept + broker ack |
| `nexus.messenger.messages.rejected` | Consumer — unroutable reject |
| `nexus.messenger.messages.dead_lettered` | Consumer — unroutable dead-letter |
| `nexus.messenger.enqueue.backpressured` | Consumer — each poll pause due to full mailbox |
| `nexus.messenger.worker.recycles` | Watchdog — each threshold-triggered shutdown |

### PSR-14 events

Subscribe to events in `Monadial\Nexus\Messenger\Event` for application-level hooks (audit log, metrics aggregation, Slack alerts):

```php title="src/Listener/MessengerEventListener.php"
use Monadial\Nexus\Messenger\Event\MessageConsumed;
use Monadial\Nexus\Messenger\Event\WorkerRecyclingTriggered;

final class MessengerEventListener
{
    public function onConsumed(MessageConsumed $event): void
    {
        // $event->message, $event->targetPath
        $this->auditLog->record($event->message, $event->targetPath);
    }

    public function onRecycling(WorkerRecyclingTriggered $event): void
    {
        // $event->reason — human-readable breach description
        $this->logger->info('Worker recycling: ' . $event->reason);
    }
}
```

All five event classes (`MessagePublished`, `MessageConsumed`, `MessageRejected`, `MessageDeadLettered`, `WorkerRecyclingTriggered`) are `final readonly` and live in `Monadial\Nexus\Messenger\Event`.

### Trace propagation

When `Observability` is provided on the producer side, every `tell()` / `publish()` call:

1. Opens a `messenger.send` Producer span using the current trace context.
2. Injects the W3C trace context (or any configured propagation format) into a `TraceContextStamp` attached to the envelope.
3. `NexusMessengerSerializer` encodes the stamp as the `X-Nexus-Trace-Context` header on the wire.

On the consumer side, `ReceiverActor` reads the `TraceContextStamp` from the decoded envelope and links the `messenger.receive` Consumer span to the producer's trace. The result is a single distributed trace that spans the broker boundary — the producer span and consumer span appear as a linked pair in your tracing backend (Jaeger, Zipkin, Grafana Tempo, etc.).

No configuration is required beyond passing `$observability` to both sides. The `TraceContextStamp` is silently skipped on decode if the header is absent or malformed — tracing errors never block message delivery.

## CLI runners

Install `nexus-actors/messenger-console` for ready-made Symfony Console commands that wrap everything covered in this guide:

```bash
composer require nexus-actors/messenger-console
```

| Command | What it does |
|---------|-------------|
| `nexus:messenger:consume` | Boots an `ActorSystem`, spawns receiver actors, optional limit-driven watchdog |
| `nexus:messenger:produce` | Deserializes a JSON body and publishes N messages to the transport |

```bash
# Consume with recycling after 10 000 messages, 3 actors
bin/console nexus:messenger:consume --receivers=3 --limit=10000 --memory-limit=256M

# Publish a test message
bin/console nexus:messenger:produce order.placed '{"orderId":"A-42","amountCents":1999}'
```

See the [nexus-messenger-console package page](/docs/packages/messenger-console) for the full option reference and `bin/console` Application wiring example.

### Swoole & threads

For horizontal scaling within a single process, install `nexus-actors/messenger-console-swoole` and use the `nexus:messenger:consume-threads` command. It boots a Swoole thread pool where each thread owns its own `ActorSystem` and a fresh transport connection:

```bash
composer require nexus-actors/messenger-console-swoole
```

```php title="src/OrderConsumerBootstrap.php"
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumerBootstrap;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class OrderConsumerBootstrap implements ThreadedConsumerBootstrap
{
    public function setup(ActorSystem $system): MessageRouter
    {
        $ref = $system->spawn(Props::fromFactory(fn() => new OrdersActor()), 'orders');

        return new MapMessageRouter([OrderPlaced::class => $ref]);
    }

    public function receiver(): ReceiverInterface
    {
        // Fresh connection per thread — broker load-balances across threads
        return new RedisTransport(Redis::connect(getenv('REDIS_URL')));
    }
}
```

```bash
# 4 threads, each recycling after 10 000 messages
bin/console nexus:messenger:consume-threads --threads=4 --limit=10000 --memory-limit=256M
```

**Key differences from `nexus:messenger:consume`:** limits are per-thread (not per process), the transport is constructed inside each thread (not injected at wiring time), and signal handling relies on the process manager (SIGTERM) rather than `pcntl`. See the [nexus-messenger-console-swoole package page](/docs/packages/messenger-console-swoole) for the full contract and option reference.

## Request/reply over the broker

The bridge supports broker request/reply: an actor asks a question, the request travels over a broker transport, a responder processes it and publishes a reply to a dedicated reply queue, and the original fiber resumes with the reply object. Both sides can be Nexus actors or plain Symfony Messenger consumers.

### Nexus↔Nexus walkthrough

```php title="src/bootstrap.php"
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Ask\MapReplySenderLocator;
use Monadial\Nexus\Messenger\Ask\ReplyQueueLifecycle;
use Monadial\Nexus\Messenger\Ask\TransportReplyChannelFactory;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Runtime\Duration;

// --- Asker side ---

$channelFactory = new TransportReplyChannelFactory(
    $amqpFactory,
    $serializer,
    'amqp://broker/nexus-replies-{name}-{instance}?queue[ttl]=300000',
    'orders-replies',
    ReplyQueueLifecycle::Ephemeral,
);

$askSupport = MessengerBridge::askSupport($system, $channelFactory);
$ordersOut  = MessengerBridge::producer($requestTransport, 'orders-out', askSupport: $askSupport);

// Spawn a fiber-based actor that asks the broker
$system->spawn(
    Props::fromBehavior(Behavior::setup(static function ($ctx) use ($ordersOut): Behavior {
        $ctx->scheduleOnce(Duration::millis(0), new DoWork());

        return Behavior::receive(static function ($ctx, object $msg) use ($ordersOut): Behavior {
            if ($msg instanceof DoWork) {
                /** @var Pong $reply */
                $reply = $ordersOut->ask(new Ping('hello'), Duration::seconds(10))->await();
                $ctx->log()->info('Got reply', ['body' => $reply->body]);
            }

            return Behavior::same();
        });
    })),
    'asker',
);

// --- Responder side (separate process or separate actor) ---

$replySenders = new MapReplySenderLocator(['orders-replies' => $replyTransport]);

$system->spawn(
    MessengerBridge::receiverProps(
        $requestTransport,
        new MapMessageRouter([Ping::class => $pingHandlerActor]),
        replySenders: $replySenders,
    ),
    'orders-receiver',
);
```

The responder actor receives `Ping` and replies via `$ctx->sender()`:

```php title="src/PingHandlerActor.php"
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\ActorHandler;

final class PingHandlerActor implements ActorHandler
{
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof Ping) {
            // $ctx->sender() is a MessengerReplyRef — tell() publishes the reply
            // to the reply transport and acks the original broker envelope.
            $ctx->sender()?->tell(new Pong('ok'));
        }

        return Behavior::same();
    }
}
```

**Deferred reply — always capture `$ctx->sender()` during the ask-message handler.** The sender is per-envelope and is only available while the ask message is being processed. If you schedule a self-message to reply asynchronously, capture the sender ref first:

```php title="src/AsyncResponderActor.php"
public function handle(ActorContext $ctx, object $message): Behavior
{
    if ($message instanceof Ping) {
        // Capture the reply ref NOW — it is only set on this message's envelope.
        $replyTo = $ctx->sender();

        // Schedule async work; $replyTo stays in scope via closure capture.
        $ctx->scheduleOnce(Duration::millis(50), new DoWorkAndReply($replyTo));
    }

    if ($message instanceof DoWorkAndReply) {
        $message->replyTo?->tell(new Pong('deferred-ok'));
    }

    return Behavior::same();
}
```

A scheduled self-message (`Poll`, `DoWork`, etc.) has no sender — `$ctx->sender()` returns `null` on those envelopes.

### Plain Symfony responder (AMQP interop)

Any Symfony Messenger consumer can answer a Nexus ask using only two headers on the reply envelope. No Nexus consumer classes are needed on the responder side.

```php title="src/ForeignResponder.php"
// The request envelope carries two headers on the wire:
//   X-Nexus-Correlation-Id  — the correlation ID to echo back
//   X-Nexus-Reply-To        — a LOGICAL name; resolve it via your own configured map
//                             NEVER construct a transport DSN from the wire value (SSRF hardening)

foreach ($requestTransport->get() as $requestEnvelope) {
    $encoded = $serializer->encode($requestEnvelope);
    $headers = $encoded['headers'];

    $correlationId = $headers['X-Nexus-Correlation-Id'] ?? null;
    $replyTo       = $headers['X-Nexus-Reply-To'] ?? null;

    if ($correlationId === null || $replyTo === null) {
        $requestTransport->ack($requestEnvelope);
        continue;
    }

    // Resolve via your own static map — never pass $replyTo directly to a transport factory.
    $replySenders = ['orders-replies' => $replyTransport];
    $sender = $replySenders[$replyTo] ?? null;

    if ($sender === null) {
        $requestTransport->reject($requestEnvelope);
        continue;
    }

    // Build and send the reply envelope: copy the correlation ID, set the type header.
    $encodedReply = [
        'body'    => serialize(new Pong('foreign-pong')),
        'headers' => [
            'type'                   => 'pong',
            'X-Nexus-Correlation-Id' => $correlationId,
        ],
    ];
    $sender->send($serializer->decode($encodedReply));
    $requestTransport->ack($requestEnvelope);
}
```

The two headers and their wire semantics are the only contract a plain responder needs. The `X-Nexus-Reply-To` value is a logical queue name — the responder must resolve it through its own configured sender map. Never construct a transport from a wire-supplied string.

### Reply channel lifecycle

| Lifecycle | Queue created | Queue torn down | Use when |
|---|---|---|---|
| `Ephemeral` | By Nexus on first ask | Left to broker (TTL / auto-delete) | Broker supports per-queue TTL or auto-delete. Default. |
| `DeleteOnShutdown` | By Nexus on first ask | Best-effort via `reset()` on `AskSupport::close()` | You control the broker and can tolerate best-effort cleanup. |
| `Persistent` | Pre-provisioned externally | Never | Queue creation is slow or costly (SQS). **Warning:** all instances sharing the queue compete for replies. Use only with a single consumer per channel name. Do not use `{instance}` in the DSN template. |

:::tip SQS
On AWS SQS, prefer `Persistent`: SQS queue creation is an asynchronous API call that takes several seconds and has per-queue costs. Pre-provision the reply SQS URL and reference it as the Persistent DSN.
:::

### Wiring with ConsumeCommand

`ConsumeCommand` (from `nexus-messenger-console`) accepts a `ReplySenderLocator` as the last constructor argument:

```php title="bin/worker.php"
use Monadial\Nexus\Messenger\Ask\MapReplySenderLocator;
use Monadial\Nexus\Messenger\Console\ConsumeCommand;

$app->add(new ConsumeCommand(
    $runtime,
    $requestTransport,
    $consumerSetup,
    replySenders: new MapReplySenderLocator([
        'orders-replies' => $replyTransport,
    ]),
));
```

This is a constructor parameter, not a command-line option — the locator is configured at wiring time.

## Complete runnable example

The [nexus-messenger-redis](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-messenger-redis) example in the monorepo demonstrates the full stack end-to-end:

- 3 competing `ReceiverActor`s polling the same Redis Streams consumer group
- `LifecycleWatchdog` recycling after N messages
- `PhpNativeSerializer` with an allow-list to prevent PHP object injection
- PSR-14 event listener printing `MessageConsumed` and `WorkerRecyclingTriggered` to stdout
- Docker Compose environment with Redis 7 included

Copy the folder to a standalone repository and `git init` it once the packages are published to Packagist.
