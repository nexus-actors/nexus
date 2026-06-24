---
title: nexus (meta-package)
related:
  - packages/core
  - packages/runtime-fiber
  - packages/serialization
  - getting-started/installation
---

# nexus (meta-package)

A single Composer dependency that pulls in `nexus-actors/core`, `nexus-actors/runtime`, `nexus-actors/runtime-fiber`, and `nexus-actors/serialization` — enough to build and run actors on PHP Fibers without further configuration.

## Install

```bash title="terminal"
composer require nexus-actors/nexus
```

## What it includes

The meta-package requires exactly these packages and nothing else:

| Package | Role |
|---|---|
| [`nexus-actors/core`](core.md) | Actor model — `ActorSystem`, `Behavior`, `Props`, `ActorRef`, supervision |
| [`nexus-actors/runtime`](runtime.md) | `Runtime` interface and shared contracts |
| [`nexus-actors/runtime-fiber`](runtime-fiber.md) | `FiberRuntime` — cooperative concurrency on PHP Fibers |
| [`nexus-actors/serialization`](serialization.md) | `MessageSerializer` and `EnvelopeSerializer` contracts + `PhpNativeSerializer` |

## Quick start vs. explicit packages

`nexus-actors/nexus` is the fastest way to get started. A single `composer require` gives you a working actor system on Fibers with serialization support.

For production deployments, prefer declaring the packages you actually use. Explicit dependencies make it clear which subsystems your application relies on, allow you to upgrade each package independently, and keep the dependency graph auditable. If your application runs on Swoole, replace `nexus-actors/runtime-fiber` with `nexus-actors/runtime-swoole` in your own `composer.json` — there is no meta-package for the Swoole variant.

```json title="composer.json — explicit production declaration"
{
    "require": {
        "nexus-actors/core": "^1.0",
        "nexus-actors/runtime": "^1.0",
        "nexus-actors/runtime-fiber": "^1.0",
        "nexus-actors/serialization": "^1.0"
    }
}
```

## Packages pulled in

- [nexus-actors/core](core.md)
- [nexus-actors/runtime-fiber](runtime-fiber.md)
- [nexus-actors/serialization](serialization.md)
