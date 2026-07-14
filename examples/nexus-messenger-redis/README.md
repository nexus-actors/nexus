# nexus-messenger-redis

End-to-end Nexus example: competing-consumer queue worker over a **Symfony Redis Stream transport** with worker recycling, PSR-14 events on stdout, and an explicit serialization allow-list.

This is **a standalone Composer project** living inside the Nexus monorepo under `examples/`. It has its own `composer.json`, `Dockerfile`, and `compose.yaml`. Copy the folder to a standalone repo and `git init` it when the Nexus packages are published to Packagist.

---

## Prerequisites

| Requirement         | Notes |
|---------------------|-------|
| PHP 8.5+            | CLI only |
| **ext-redis**       | phpredis extension — required by `symfony/redis-messenger`. Not bundled in standard PHP images; the provided `Dockerfile` installs it via PECL. |
| Docker + Compose v2 | For the included `compose.yaml` |
| Redis 7             | Provided by the included compose service |

> **Note:** The root Nexus CI image does not include ext-redis, so this example cannot be executed end-to-end within the monorepo's standard `docker compose exec php` environment. Build the example's own image (`docker compose build app`) or install ext-redis separately.

---

## What it demonstrates

| Concern | Where |
|---------|-------|
| **Competing consumers** | 3 `ReceiverActor`s poll the same Redis consumer group independently |
| **At-least-once delivery** | Ack fires only after the actor mailbox accepts the envelope |
| **Worker recycling** | `LifecycleWatchdog` shuts down gracefully after N messages; your process manager restarts the worker |
| **Serialization allow-list** | `PhpNativeSerializer([OrderPlaced::class])` prevents PHP Object Injection (CWE-502) |
| **Binary payloads** | `SERIALIZER=msgpack` switches to `MessagePackMessageSerializer` (compact MessagePack bodies) |
| **PSR-14 events** | `StdoutDispatcher` prints `MessageConsumed` and `WorkerRecyclingTriggered` |
| **PSR-3 logging** | Monolog forwarded to stdout, passed into `ActorSystem::create()` |
| **Stable wire names** | `#[MessageType('order-placed')]` + `TypeRegistry` decouple the wire type from the PHP class name |

---

## Quick start

```bash
# 1. Build the example image (installs PHP + ext-redis + Composer)
docker compose build app

# 2. Install Composer dependencies
docker compose run --rm app composer install

# 3. Start Redis
docker compose up -d redis

# 4. Publish 20 orders to the stream
docker compose run --rm app php bin/produce.php 20

# 5. Start the worker (blocks; Ctrl-C to stop)
docker compose run --rm app php bin/worker.php
```

Alternatively, use the Symfony Console runner from `nexus-actors/messenger-console`:

```bash
# Consume with the Console command (--limit stops after N messages)
docker compose run --rm app php bin/console nexus:messenger:consume --receivers=3 --limit=50

# Publish via the Console command. nexus:messenger:produce parses the JSON body
# through the CONFIGURED serializer, so the body format MUST match SERIALIZER.
# The default SERIALIZER=php-native expects a PHP-serialized body and rejects
# JSON with "Failed to unserialize data" — pass SERIALIZER=json for a JSON body:
docker compose run --rm -e SERIALIZER=json app php bin/console nexus:messenger:produce order-placed \
  '{"orderId":"A-1","customerId":"c-1","amountCents":1999}'
```

> The console consumer must run with the **same** `SERIALIZER` value as the producer,
> otherwise it cannot decode the bodies the producer wrote.

---

## What to observe

### Producer output (`bin/produce.php`)

```
Published order-1
Published order-2
...
Done — 20 messages published to stream "orders" on redis://redis:6379
```

### Worker output (`bin/worker.php`)

```
[2026-07-03T...] INFO nexus-worker: Worker starting {"message_limit":50,"receiver_count":3,...}
[PSR-14] MessageConsumed  target=/messenger-worker/order-processor  message=Monadial\Nexus\Example\MessengerRedis\Message\OrderPlaced
[PSR-14] MessageConsumed  target=/messenger-worker/order-processor  message=Monadial\Nexus\Example\MessengerRedis\Message\OrderPlaced
...
[PSR-14] WorkerRecyclingTriggered  reason="processed 50 messages, reaching the limit of 50"
[2026-07-03T...] INFO nexus-worker: Worker stopped — process manager should restart this worker.
```

### Competing consumers

Because all three `ReceiverActor`s share the same Redis consumer group, each pending message is handed to exactly one consumer — work-queue semantics. Increase `RECEIVER_COUNT` (or run multiple worker processes) to scale throughput.

### Worker recycling + process manager

`LifecycleWatchdog` calls `ActorSystem::shutdown()` after `MESSAGE_LIMIT` messages are processed. The actor system drains all in-flight messages before the PHP process exits cleanly. Configure your process manager to restart on exit:

**systemd unit snippet:**
```ini
[Service]
ExecStart=/usr/bin/php /app/bin/worker.php
Restart=always
RestartSec=1
```

**supervisord snippet:**
```ini
[program:nexus-worker]
command=php /app/bin/worker.php
autostart=true
autorestart=true
```

**Kubernetes Deployment snippet:**
```yaml
spec:
  restartPolicy: Always
  containers:
    - name: nexus-worker
      image: nexus-messenger-redis:prod
      command: ["php", "bin/worker.php"]
      env:
        - name: REDIS_DSN
          value: "redis://redis-service:6379"
        - name: MESSAGE_LIMIT
          value: "10000"
```

### PSR-14 events

`StdoutDispatcher` (in `src/Event/`) is a minimal inline implementation. Replace it with your framework's dispatcher:

```php
// Symfony
use Symfony\Component\EventDispatcher\EventDispatcher;
$events = new EventDispatcher();
$events->addListener(MessageConsumed::class, fn($e) => ...);

// Laravel
$events = app(\Illuminate\Contracts\Events\Dispatcher::class);
```

---

## Ask/reply

The console worker answers broker-based asks, and `bin/ask.php` is a runnable
asker that issues one request and prints the correlated reply.

**Responder side** — `bin/console` wires a `MapReplySenderLocator(['replies' => $repliesTransport])`
into `ConsumeCommand`, mapping the logical channel name `replies` to a second
Redis Stream (`REPLY_STREAM`, default `replies`). When a request carries the
`X-Nexus-Correlation-Id` + `X-Nexus-Reply-To` headers, the receiver delivers it
with a `MessengerReplyRef` as the sender; `OrderProcessor` answers with
`$ctx->sender()?->tell(new OrderAccepted(...))`, and the request is acked only
after the reply is published (process-ack). The reply-to header carries a
logical name only — reply destinations are always resolved through the
configured locator, never constructed from wire values.

**Asker side** — `bin/ask.php` wires `MessengerBridge::askSupport()` with a
`TransportReplyChannelFactory` (channel name `replies`, `ReplyQueueLifecycle::Persistent`
over the shared reply stream), builds a producer with that ask support, and
calls `MessengerActorRef::ask($request, $timeout)->await()` inside a Fiber. The
`ask()` stamps the correlation + reply-to headers; `AskSupport` lazily spawns
the `nexus-ask-replies` consumer that resolves the returned `Future` when the
matching reply lands on the reply stream.

Run the responder first (keep it running), then the asker in a second terminal.
Use the **same `SERIALIZER`** on both sides — the request and reply share one
serializer:

```bash
# terminal 1 — responder (blocks, answers asks):
docker compose run --rm -e SERIALIZER=json app php bin/console nexus:messenger:consume --receivers=1

# terminal 2 — asker (one request, prints the reply, exits):
docker compose run --rm -e SERIALIZER=json app php bin/ask.php A-42
```

Asker output:

```
INFO nexus-asker: ask → publishing request  order_id=A-42
INFO nexus-asker: ask ← reply received  order_id=A-42 status=accepted
```

---

## Threaded consumer (Swoole thread pool)

`bin/worker.php` and `bin/console` scale by spawning competing `ReceiverActor`
Fibers **inside one process/thread**. `bin/consume-threads.php` scales across
**OS threads** instead: it runs `nexus:messenger:consume-threads`, a Swoole
thread pool where each thread owns an independent `ActorSystem` + `SwooleRuntime`,
its own Redis connection, and N competing receivers. The broker load-balances
across threads because each thread holds a distinct consumer-group connection.

**Requires ext-swoole ≥ 6.2.1 built with `--enable-swoole-thread` (ZTS PHP).**
The example's default Fiber-only image cannot run it — use a Swoole ZTS image
(see the monorepo `docker/Dockerfile` `php-swoole` target).

The command takes only a **class-string** — `OrderConsumerBootstrap::class` — so
no live object crosses the thread boundary. Each thread constructs its own
bootstrap via `new OrderConsumerBootstrap()`, whose two methods run per-thread:

- `setup(ActorSystem $system): MessageRouter` — spawns `OrderProcessor` on that
  thread's system and returns the router that targets it.
- `receiver(): ReceiverInterface` — opens a **fresh** Redis connection for that
  thread (never a shared object).

Per-thread config (`REDIS_DSN`, `REDIS_STREAM`, `CONSUMER_GROUP`, `SERIALIZER`)
is read from environment variables by the bootstrap inside each thread, keeping
the class argument-free.

```bash
# 4 threads × 2 receivers each = 8 competing consumers; each thread recycles
# after 1000 messages (--limit is PER-THREAD, each has its own LifecycleWatchdog).
docker compose run --rm app php bin/consume-threads.php --threads=4 --receivers=2 --limit=1000

# No limits → runs until SIGTERM (stop via the process manager).
docker compose run --rm app php bin/consume-threads.php --threads=4
```

| Option | Default | Description |
|--------|---------|-------------|
| `--threads` / `-t` | `2` | Worker threads |
| `--receivers` / `-r` | `1` | Competing receivers **per thread** |
| `--limit` | _(none)_ | Stop each thread after N messages |
| `--memory-limit` | _(none)_ | Stop each thread at e.g. `128M`, `1G` |
| `--time-limit` | _(none)_ | Stop each thread after N seconds |
| `--poll-interval` | `100` | Receiver poll interval (ms) |
| `--dead-letters` | off | Route unroutable messages to dead letters |

> All limit options are **per-thread**: `--limit=1000` on a 4-thread pool means
> each thread processes up to 1000 messages before recycling, not 1000 total.

---

## Binary payloads with MessagePack

The serializer is built in `src/Serialization/SerializerFactory.php`, shared by all three bin scripts. The `SERIALIZER` environment variable switches the message body format — producer and worker must use the same value:

```bash
# Publish and consume with MessagePack bodies
SERIALIZER=msgpack docker compose run --rm app php bin/produce.php 20
SERIALIZER=msgpack docker compose run --rm app php bin/worker.php
```

| Value | Serializer | Wire format |
|-------|------------|-------------|
| `php-native` (default) | `PhpNativeSerializer` with allow-list | PHP `serialize()` — PHP-only, fastest |
| `json` | `ValinorMessageSerializer` | JSON — human-readable, interoperable |
| `msgpack` | `MessagePackMessageSerializer` | MessagePack — compact binary, interoperable |

**What to observe:**

- **Payload size** — inspect the stream entries with `docker compose exec redis redis-cli XRANGE orders - + COUNT 1`. MessagePack bodies drop field-name quoting, braces, and whitespace; the `OrderPlaced` body shrinks noticeably versus JSON and PHP-native. Redis Streams carries raw binary bytes without any escaping.
- **Serialization telemetry** — the msgpack path is wrapped in `TracingMessageSerializer` when an enabled `Observability` is passed to `SerializerFactory::fromEnvironment()`. The bin scripts default to no observability; wire `nexus-actors/observability-otel` and pass its `Observability` instance to see `serialization.serialize` / `serialization.deserialize` spans plus the `nexus.serialization.operations`, `nexus.serialization.bytes`, `nexus.serialization.duration`, and `nexus.serialization.failures` metrics — the bytes histogram makes the msgpack size drop directly measurable.
- **Backend selection** — `MessagePackMessageSerializer` uses the native `ext-msgpack` extension when loaded and falls back to the pure-PHP `rybakit/msgpack` library otherwise (both are wire-compatible). This example's image has no ext-msgpack, so the pure-PHP path runs.

> **Transport caveat:** Redis Streams (and AMQP, and Doctrine with a BLOB column) are binary-safe. Text-oriented transports — Amazon SQS most prominently — reject or mangle raw binary bodies; stick to `json` there or base64-encode the body at the transport boundary.

---

## Environment variables

| Variable          | Default              | Description |
|-------------------|----------------------|-------------|
| `REDIS_DSN`       | `redis://redis:6379` | Redis connection string |
| `REDIS_STREAM`    | `orders`             | Redis Stream / queue name |
| `REPLY_STREAM`    | `replies`            | Reply stream for ask/reply (console worker only) |
| `CONSUMER_GROUP`  | `nexus-workers`      | Redis consumer group name |
| `RECEIVER_COUNT`  | `3`                  | Number of competing ReceiverActors |
| `MESSAGE_LIMIT`   | `50`                 | Watchdog recycles after this many messages |
| `SERIALIZER`      | `php-native`         | Message body format: `php-native`, `json` (Valinor), or `msgpack` |

---

## SQS variant

Swap the Redis transport for Amazon SQS with no changes to actor code or serializer wiring — only the transport construction differs.

```bash
composer require symfony/amazon-sqs-messenger
```

```php
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransportFactory;

$factory = new AmazonSqsTransportFactory();
$transport = $factory->createTransport(
    'sqs://sqs.eu-west-1.amazonaws.com/123456789012/orders',
    [
        'access_key' => $_SERVER['AWS_ACCESS_KEY_ID'],
        'secret_key' => $_SERVER['AWS_SECRET_ACCESS_KEY'],
    ],
    $serializer,   // same NexusMessengerSerializer as above
);

// Everything else — spawnReceivers, watchdogProps, etc. — is identical.
```

> SQS provides at-least-once delivery natively. Set `RECEIVER_COUNT=1` per
> process and scale horizontally across multiple ECS tasks / EC2 instances
> instead of spawning many Fibers against the same queue URL.

---

## Architecture

```
bin/produce.php                          bin/worker.php
────────────────                         ──────────────────────────────────────────
MessengerBridge::producer()              ActorSystem (FiberRuntime)
  → MessengerActorRef::tell()              ├─ ReceiverActor-0  ─┐
      → RedisTransport::send()             ├─ ReceiverActor-1  ─┤ poll same consumer
                                           ├─ ReceiverActor-2  ─┘ group; ack on accept
Redis Stream ──────────────────────────────────────────────────────────────────────
                                                      │ route via MapMessageRouter
                                                      ▼
                                           OrderProcessor actor
                                              log to stdout

                                           LifecycleWatchdog
                                              monitors MessagesProcessed
                                              → fires WorkerRecyclingTriggered (PSR-14)
                                              → ActorSystem::shutdown()
```
