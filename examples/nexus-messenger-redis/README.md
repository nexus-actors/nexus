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

# Publish via the Console command
docker compose run --rm app php bin/console nexus:messenger:produce order-placed \
  '{"orderId":"A-1","customerId":"c-1","amountCents":1999}'
```

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

## Environment variables

| Variable          | Default              | Description |
|-------------------|----------------------|-------------|
| `REDIS_DSN`       | `redis://redis:6379` | Redis connection string |
| `REDIS_STREAM`    | `orders`             | Redis Stream / queue name |
| `CONSUMER_GROUP`  | `nexus-workers`      | Redis consumer group name |
| `RECEIVER_COUNT`  | `3`                  | Number of competing ReceiverActors |
| `MESSAGE_LIMIT`   | `50`                 | Watchdog recycles after this many messages |

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
