---
title: nexus-messenger-console-swoole
related:
  - packages/messenger-console
  - packages/worker-pool-swoole
  - packages/messenger
  - guides/messenger-bridge
---

# nexus-messenger-console-swoole

Threaded Symfony Console runner for the Nexus messenger bridge — `nexus:messenger:consume-threads` command using the Swoole worker pool.

Each worker thread owns an independent `ActorSystem`, `SwooleRuntime`, and a fresh transport connection. The broker naturally load-balances: N threads become N competing consumers without any additional configuration.

## What's in this package

- `ThreadedConsumerBootstrap` — interface extending `ConsumerSetup` with `receiver(): ReceiverInterface`; the pool instantiates it fresh in every thread
- `ThreadedConsumeCommand` (`nexus:messenger:consume-threads`) — boots N Swoole worker threads and wires a `ReceiverActor` per thread via the bootstrap contract

## Install

```bash
composer require nexus-actors/messenger-console-swoole
```

Requires PHP 8.5.7+, `ext-swoole >= 6.2.1` with thread support (`--enable-swoole-thread`), and `nexus-actors/messenger-console`.

## Quick start

### 1. Implement ThreadedConsumerBootstrap

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumerBootstrap;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
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
        // Return a FRESH connection per thread — broker load-balances across them.
        // $serializer is your NexusMessengerSerializer instance.
        $connection = Connection::fromDsn(getenv('REDIS_DSN') . '/orders', ['group' => 'workers']);

        return new RedisTransport($connection, $serializer);
    }
}
```

### 2. Register the command

```php title="bin/console"
#!/usr/bin/env php
<?php

declare(strict_types=1);

use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumeCommand;
use Symfony\Component\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

$app = new Application('nexus-worker', '1.0.0');
$app->addCommand(new ThreadedConsumeCommand(OrderConsumerBootstrap::class));
$app->run();
```

### 3. Run

```bash
# 4 threads, 2 competing receivers per thread
bin/console nexus:messenger:consume-threads --threads=4 --receivers=2

# Per-thread limits — each thread recycles after 10 000 messages
bin/console nexus:messenger:consume-threads --threads=4 --limit=10000 --memory-limit=256M
```

## nexus:messenger:consume-threads

Boots a Swoole thread pool with N worker threads. Each thread independently:
1. Instantiates `new $bootstrapClass()` (fresh per thread)
2. Calls `setup($system)` to spawn handler actors and get a `MessageRouter`
3. Calls `receiver()` to open a fresh transport connection
4. Spawns competing `ReceiverActor`(s) on that connection
5. Optionally wires a `LifecycleWatchdog` when any limit option is set
6. Runs its `ActorSystem` event loop until shutdown

### Options

| Option | Short | Default | Description |
|--------|-------|---------|-------------|
| `--threads` | `-t` | `2` | Number of worker threads |
| `--receivers` | `-r` | `1` | Number of competing `ReceiverActor`s per thread |
| `--limit` | — | none | Stop each thread after processing this many messages |
| `--memory-limit` | — | none | Stop each thread when memory usage reaches this value (e.g. `128M`, `1G`) |
| `--time-limit` | — | none | Stop each thread after running for this many seconds |
| `--poll-interval` | — | `100` | Receiver poll interval in milliseconds |
| `--dead-letters` | — | off | Forward unroutable messages to dead letters instead of rejecting them |

## Key concepts

### Fresh transport connection per thread

`ThreadedConsumerBootstrap::receiver()` is called inside each worker thread. Always return a **new connection** — never share an object created on the main thread. Thread-safe transports (e.g. Redis with a per-thread TCP socket) work natively; connection-pool libraries that are not thread-safe need one instance per thread.

### Limits are per-thread

All limit options are evaluated per thread. Each thread has its own `LifecycleWatchdog`:
- `--limit=1000` on a 4-thread pool → each thread processes up to 1000 messages before recycling, not 1000 total
- The broker's own competing-consumer semantics ensure work is distributed without double-processing

### Signal handling

The main thread blocks in `Swoole\Thread\Pool::start()`. SIGTERM/SIGINT stops the pool; each worker thread shuts down its `ActorSystem`. No `SignalableCommandInterface` is used — rely on the process manager (systemd, supervisord, Docker `STOPSIGNAL`) to control the lifecycle.

### Thread-boundary rule

The configure closure passed to the Swoole worker pool is serialized via `opis/closure` and revived in each thread's PHP runtime. Only scalars and class-strings cross the boundary — live objects (logger, transport, router) cannot be captured. `ThreadedConsumerBootstrap` solves this by naming the class as a string: `new $bootstrapClass()` constructs a fresh instance per thread.

## Comparison with nexus:messenger:consume

| | `nexus:messenger:consume` | `nexus:messenger:consume-threads` |
|---|---|---|
| **Runtime** | `FiberRuntime` (or any injected `Runtime`) | `SwooleRuntime` per thread |
| **Scaling unit** | Process (one actor system per process) | Thread (one actor system per thread) |
| **Transport** | Injected at wiring time | Created fresh per thread by bootstrap |
| **Signal handling** | `SignalableCommandInterface` (SIGINT/SIGTERM) | Process manager (SIGTERM stops pool) |
| **Limits** | Per process | Per thread |
| **When to use** | Single-host, fiber-based, easy DI | Multi-thread Swoole, broker load-balance |

## See also

- [nexus-messenger-console package](/docs/packages/messenger-console) — single-threaded runner and `ConsumerSetup` contract
- [nexus-worker-pool-swoole package](/docs/packages/worker-pool-swoole) — underlying thread pool
- [Messenger bridge guide](/docs/guides/messenger-bridge) — transport wiring, routing, and scaling patterns
