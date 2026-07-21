# nexus-logger

Async actor-backed PSR-3 logger for Nexus. Application code calls a PSR-3 `LoggerInterface`; each call constructs a `Record` and enqueues it via `tell()` on a `LogActor`. The actor drains the mailbox on its own turn and dispatches each record to the registered handlers (console, file, custom).

Inspired by Monolog's record/handler/formatter pipeline, but back-pressured by the actor mailbox instead of blocking the calling thread.

## Install

```bash
composer require nexus-actors/logger
```

## Quickstart

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Logger\Formatter\JsonFormatter;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Handler\FileHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;

$logger = NexusLogger::create($system, 'app')
    ->minLevel(Level::Debug)
    ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
    ->handler(new FileHandler('/var/log/app.log', new JsonFormatter()))
    ->build();

$logger->info('user {name} logged in', ['name' => 'tomas', 'userId' => 42]);
// Console: [2026-06-14T13:50:01.234Z] app.INFO: user tomas logged in {"userId":42}
// File:    {"channel":"app","context":{"userId":42},"level":"info",...}
```

## Why async?

PSR-3 implementations like Monolog are synchronous — calling `info()` from a hot path waits for `fwrite` to return. With an actor in the middle, the caller pays only the cost of placeholder interpolation and a mailbox enqueue. All I/O happens on the actor's turn under whatever runtime the `ActorSystem` is using (Fiber, Swoole, Step).

This matters most for:
- WebSocket frame handlers that log per message
- HTTP request handlers that emit structured access logs
- Anything in a coroutine where blocking on `fwrite` would block other coroutines

## Architecture

- `Logger` (PSR-3 façade) — interpolates placeholders, checks `minLevel`, constructs a `Record`, enqueues to the `LogActor`.
- `Record` — immutable value object: level, message, context, channel, timestamp.
- `LogActor` — `StatefulActorHandler<Record, list<Handler>>`. Iterates handlers per record, catches per-handler exceptions so one bad sink can't starve the others.
- `Handler` interface — `handle(Record): void`. Built-ins: `ConsoleHandler` (stream resource) and `FileHandler` (append + `flock(LOCK_EX)`).
- `Formatter` interface — `format(Record): string`. Built-ins: `LineFormatter` (Monolog-style single line) and `JsonFormatter` (ndjson).

## Channels

Like Monolog, every record carries a channel label. Fork a sub-logger for a subsystem:

```php
$httpLogger = $logger->withChannel('http');
$wsLogger = $logger->withChannel('ws');
$httpLogger->info('request', ['path' => '/users']);
// Console: [...] http.INFO: request {"path":"/users"}
```

Channels share the underlying actor — there's only ever one sink per `NexusLogger::build()`.

## Level filtering

`minLevel` is checked at the façade BEFORE the `Record` is constructed, so below-threshold calls cost a single int comparison and don't queue anything.

```php
$logger = NexusLogger::create($system, 'app')->minLevel(Level::Warning)->handler(...)->build();
$logger->debug('skipped');  // not enqueued
$logger->error('kept');     // enqueued + handled
```

## Multi-worker / multi-thread file safety

`FileHandler` opens, locks with `flock(LOCK_EX)`, writes one line, flushes, unlocks, and closes per record. This is safe under Swoole worker (multi-process) and Swoole thread (multi-thread) modes — each runtime spawns its own `ActorSystem` + `LogActor`, and they all serialise on the file lock.

For very high write rates use a non-file transport (syslog, custom handler over a queue, etc).

## Status

Stable for the eight PSR-3 levels with line + JSON formatters and console + file handlers. The handler interface is open — implement `Monadial\Nexus\Logger\Handler` for any custom sink.

## Repository

> **Read-only subtree split** of [nexus-actors/nexus](https://github.com/nexus-actors/nexus).
> Report issues and send pull requests to the monorepo — this repository only receives
> automated pushes and release tags.
