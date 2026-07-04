---
title: nexus-messenger-console
related:
  - packages/messenger
  - packages/core
  - packages/serialization
  - guides/messenger-bridge
---

# nexus-messenger-console

Symfony Console runners for the nexus-messenger bridge — `nexus:messenger:consume` and `nexus:messenger:produce` commands.

Keeping `nexus-messenger` free of `symfony/console` is intentional: the bridge has no hard dependency on the Console component. Install this package when you want to drive a Nexus queue worker or send test messages from the command line.

## What's in this package

- `ConsumeCommand` (`nexus:messenger:consume`) — boots a Nexus `ActorSystem`, spawns N competing `ReceiverActor`s, and optionally wires a `LifecycleWatchdog` for limit-based recycling
- `ProduceCommand` (`nexus:messenger:produce`) — resolves a type name from a `TypeRegistry`, deserializes a JSON body, and publishes one or more messages via `MessengerBridge::gateway()`
- `MemoryLimit` — parses human-readable memory strings (`128M`, `1G`, K/M/G suffixes) to bytes; used internally by `ConsumeCommand`
- `ConsumerSetup` — interface for wiring handler actors after the `ActorSystem` boots; implement when actor refs must be created on the system that `ConsumeCommand` boots
- `CallbackConsumerSetup` — closure-based implementation of `ConsumerSetup`

## Install

```bash
composer require nexus-actors/messenger-console
```

Requires `nexus-actors/messenger`, `nexus-actors/core`, `nexus-actors/runtime`, `nexus-actors/serialization`, and `symfony/console ^7.4 || ^8.0`.

## Wiring a Console Application

Register both commands in a standard Symfony `Application`. Because `ConsumeCommand` boots its own `ActorSystem`, use `CallbackConsumerSetup` to spawn handler actors inside the callback where the live system is available:

```php title="bin/console"
#!/usr/bin/env php
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Console\CallbackConsumerSetup;
use Monadial\Nexus\Messenger\Console\ConsumeCommand;
use Monadial\Nexus\Messenger\Console\ProduceCommand;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use Symfony\Component\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

$runtime    = new FiberRuntime();
$transport  = /* your SenderInterface + ReceiverInterface */;
$registry   = new TypeRegistry();
$registry->registerFromAttribute(OrderPlaced::class);
$serializer = new ValinorMessageSerializer($registry);

$app = new Application('nexus-worker', '1.0.0');
$app->add(new ConsumeCommand(
    $runtime,
    $transport,
    new CallbackConsumerSetup(static function (ActorSystem $system): MessageRouter {
        $ref = $system->spawn(Props::fromFactory(fn() => new OrdersActor()), 'orders');

        return new MapMessageRouter([OrderPlaced::class => $ref]);
    }),
));
$app->add(new ProduceCommand($transport, $serializer, $registry));
$app->run();
```

A plain `MessageRouter` is accepted as the third argument when actor refs are already available at wiring time (for example, refs obtained from a pre-existing system or a DI container that owns its own actor system).

## nexus:messenger:consume

Boots a Nexus `ActorSystem`, spawns receiver actors on the injected transport, and blocks until shutdown (signal or limit threshold).

```bash
# Run indefinitely, stop with Ctrl-C
bin/console nexus:messenger:consume

# Stop after 10 000 messages
bin/console nexus:messenger:consume --limit=10000

# Stop when memory exceeds 256 MB
bin/console nexus:messenger:consume --memory-limit=256M

# Stop after one hour
bin/console nexus:messenger:consume --time-limit=3600

# All limits at once, 3 competing consumers
bin/console nexus:messenger:consume --receivers=3 --limit=10000 --memory-limit=256M --time-limit=3600
```

### Options

| Option | Short | Default | Description |
|--------|-------|---------|-------------|
| `--receivers` | `-r` | `1` | Number of competing `ReceiverActor`s polling the same transport |
| `--limit` | — | none | Stop after processing this many messages |
| `--memory-limit` | — | none | Stop when PHP memory usage reaches this value (e.g. `128M`, `1G`) |
| `--time-limit` | — | none | Stop after running for this many seconds |
| `--poll-interval` | — | `100` | Receiver poll interval in milliseconds |
| `--dead-letters` | — | off | Forward unroutable messages to dead letters instead of rejecting them |

**Limit wiring:** when any limit option is given, a `LifecycleWatchdog` is spawned and wired as the receivers' `processedListener`. Processed message counts flow from the receivers to the watchdog, which triggers graceful `ActorSystem::shutdown()` when a threshold is reached. When no limits are given, no watchdog is created and the command runs until SIGINT/SIGTERM.

**Signal handling:** `ConsumeCommand` implements `SignalableCommandInterface`. SIGINT and SIGTERM trigger a 10-second graceful shutdown (drain in-flight messages, then stop). Requires `ext-pcntl`; on systems without it the command runs until the watchdog or process manager stops it.

## nexus:messenger:produce

Publishes one or more messages to the injected transport by resolving a registered type name and deserializing a JSON body.

```bash
# Publish a single message
bin/console nexus:messenger:produce order.placed '{"orderId":"A-42","amountCents":1999}'

# Publish 100 identical messages
bin/console nexus:messenger:produce order.placed '{"orderId":"load-test","amountCents":100}' --count=100
```

### Arguments & options

| Name | Type | Description |
|------|------|-------------|
| `type` | Argument | Registered type name (from `#[MessageType]` attribute or `TypeRegistry::register()`) |
| `body` | Argument | Message body as a JSON object |
| `--count` / `-c` | Option (default `1`) | Number of identical messages to publish |

An unknown type name produces a clear error and exits with `Command::FAILURE` — no messages are sent.

## Using SwooleRuntime

`ConsumeCommand` accepts any `Runtime` implementation — pass `new SwooleRuntime()` to run the actor system on Swoole coroutines instead of Fibers:

```php
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

$app->add(new ConsumeCommand(
    new SwooleRuntime(),
    $transport,
    $setup,
));
```

This keeps the single-process model while gaining Swoole's async I/O for handlers that make outbound network calls. For multi-thread horizontal scaling within one process, see [nexus-messenger-console-swoole](/docs/packages/messenger-console-swoole).

## See also

- [Messenger bridge guide](/docs/guides/messenger-bridge)
- [nexus-messenger package](/docs/packages/messenger)
- [nexus-messenger-console-swoole package](/docs/packages/messenger-console-swoole) — threaded `nexus:messenger:consume-threads` command over the Swoole worker pool
- [Redis example app](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-messenger-redis) — includes a `bin/console` wiring both commands to the Redis transport
