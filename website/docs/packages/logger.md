---
sidebar_position: 5
title: nexus-logger
related:
  - packages/core
  - packages/http-server-swoole-threads
  - scaling/overview
---

# nexus-logger

Async actor-backed PSR-3 logger where log calls return immediately — formatting and I/O happen on a dedicated `LogActor` turn, off the request path.

## What's in this package

- `NexusLogger` — fluent builder; returns a PSR-3 `LoggerInterface`
- `Logger` — PSR-3 façade; `withChannel()` and `withMinLevel()` fork lightweight sub-loggers
- `Record` — immutable log record; `context` (per-call) and `extra` (MDC/processors)
- `Mdc` — mapped diagnostic context; `putStatic()` for thread-wide, `put()` for coroutine/fiber-scoped
- `ConsoleHandler`, `FileHandler`, `ThreadQueueHandler` — output handlers
- `LineFormatter`, `JsonFormatter` — native formatters
- `MonologHandlerAdapter`, `MonologFormatterAdapter` — drop-in Monolog interop
- `CallerInfoProcessor` — captures call site via `debug_backtrace()`; level-gated for performance
- `Level` enum — aligned with PSR-3 `LogLevel`

## Install

```bash
composer require nexus-actors/logger
```

## Quick example

<!-- verify:skip: requires a running actor system -->
```php title="src/Bootstrap/LoggerSetup.php" verify:skip
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;

$logger = NexusLogger::create($system, 'app')
    ->minLevel(Level::Info)
    ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
    ->build();

$logger->info('user {name} logged in', ['name' => 'tomas', 'userId' => 42]);
// → [2026-06-14T13:50:01.234Z] app.INFO: user tomas logged in {"userId":42}
```

For multi-thread Swoole deployments, use `ThreadQueueHandler` to push formatted lines onto a shared `Swoole\Thread\Queue` and drain them with a single dedicated writer thread — no locks, no per-write `fopen`.

## See also

- [nexus-http-server-swoole-threads](./http-server-swoole-threads.md) — async logging setup with thread-mode servers
- [nexus-core](./core.md) — `ActorSystem` the logger actor runs on
