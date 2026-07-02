---
sidebar_position: 5
title: Module selector
related:
  - getting-started/installation
  - getting-started/quick-start
  - runtimes/overview
  - getting-started/local-dev-without-docker
---

# Module selector

This page maps what you want to build to the packages you need. Each row gives the exact `composer require` commands for that scenario.

## Choosing your packages

- **Use `nexus-actors/nexus`** when you want the full core stack (actors + Fiber runtime + serialization) in one command.
- **Use individual packages** when you want precise control over what lands in your vendor directory.
- **Add `nexus-actors/worker-pool-swoole`** when you need true multi-thread actor pools via Swoole threads.
- **Add `nexus-actors/persistence`** (plus a store adapter) when your actors must survive process restarts.

---

## Actor system only

Spawn actors, send messages, define behaviors, supervise children. No HTTP, no persistence, no worker pool.

```bash
# core abstractions + Fiber runtime + serialization
composer require nexus-actors/nexus
```

Or install the pieces individually:

```bash
composer require nexus-actors/core
composer require nexus-actors/runtime-fiber
composer require nexus-actors/serialization
```

For static analysis:

```bash
composer require --dev nexus-actors/psalm
composer require --dev nexus-actors/runtime-step
```

---

## Actor system + HTTP

Build HTTP handlers that delegate to actors. Requires the Swoole HTTP server.

```bash
composer require nexus-actors/nexus
composer require nexus-actors/http
composer require nexus-actors/http-server-swoole
```

For authentication and authorization middleware:

```bash
composer require nexus-actors/http-auth
```

For WebSocket support:

```bash
composer require nexus-actors/http-ws
```

---

## Actor system + persistence

Event-sourced or durable-state actors that survive restarts. Choose one store adapter.

```bash
# Core actor stack
composer require nexus-actors/nexus

# Persistence abstractions
composer require nexus-actors/persistence

# Store adapter — pick one:
composer require nexus-actors/persistence-dbal       # DBAL (raw SQL)
composer require nexus-actors/persistence-doctrine   # Doctrine ORM
```

For Doctrine ORM entity behaviors:

```bash
composer require nexus-actors/doctrine-dbal
composer require nexus-actors/doctrine-orm
```

---

## Actor system + worker pool (Swoole threads)

Scale actors across OS threads using Swoole's ZTS thread API. Requires ZTS PHP 8.5 and Swoole 6.0+ compiled with `--enable-swoole-thread`.

:::caution ZTS PHP required
Standard Homebrew and apt PHP builds are not ZTS. Use the Docker setup — the `php-swoole` service in `compose.yaml` ships ZTS PHP 8.5 with Swoole 6.0.
:::

```bash
composer require nexus-actors/nexus
composer require nexus-actors/runtime-swoole
composer require nexus-actors/worker-pool
composer require nexus-actors/worker-pool-swoole
```

---

## Full example application

The meta-package plus HTTP server plus persistence gives you everything the example app uses:

```bash
composer require nexus-actors/nexus
composer require nexus-actors/http
composer require nexus-actors/http-server-swoole
composer require nexus-actors/http-auth
composer require nexus-actors/persistence
composer require nexus-actors/persistence-doctrine
composer require nexus-actors/doctrine-orm
```

---

## Package reference

| Goal | Package | Composer name |
|---|---|---|
| Actor model core | `nexus-core` | `nexus-actors/core` |
| Fiber runtime | `nexus-runtime-fiber` | `nexus-actors/runtime-fiber` |
| Swoole runtime | `nexus-runtime-swoole` | `nexus-actors/runtime-swoole` |
| Step runtime (tests) | `nexus-runtime-step` | `nexus-actors/runtime-step` |
| Serialization | `nexus-serialization` | `nexus-actors/serialization` |
| HTTP actors | `nexus-http` | `nexus-actors/http` |
| HTTP Swoole server | `nexus-http-server-swoole` | `nexus-actors/http-server-swoole` |
| HTTP auth | `nexus-http-auth` | `nexus-actors/http-auth` |
| WebSockets | `nexus-http-ws` | `nexus-actors/http-ws` |
| HTTP toolkit | `nexus-http-toolkit` | `nexus-actors/http-toolkit` |
| Persistence | `nexus-persistence` | `nexus-actors/persistence` |
| Persistence DBAL | `nexus-persistence-dbal` | `nexus-actors/persistence-dbal` |
| Persistence Doctrine | `nexus-persistence-doctrine` | `nexus-actors/persistence-doctrine` |
| Doctrine ORM | `nexus-doctrine-orm` | `nexus-actors/doctrine-orm` |
| Worker pool | `nexus-worker-pool` | `nexus-actors/worker-pool` |
| Worker pool Swoole | `nexus-worker-pool-swoole` | `nexus-actors/worker-pool-swoole` |
| PSR logger bridge | `nexus-logger` | `nexus-actors/logger` |
| App bootstrap | `nexus-app` | `nexus-actors/app` |
| Psalm plugin | `nexus-psalm` | `nexus-actors/psalm` |
| Meta-package | `nexus` | `nexus-actors/nexus` |

## Next steps

- [Installation](./installation.md) — verify your setup with a smoke test.
- [Quick start](./quick-start.md) — build your first counter actor.
- [Runtimes](../runtimes/overview.md) — choose between Fiber and Swoole for your workload.
