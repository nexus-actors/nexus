---
sidebar_position: 1
title: Installation
related:
  - getting-started/quick-start
  - getting-started/concepts
  - runtimes/overview
---

# Installation

This page covers installing Nexus into your PHP project. Nexus requires PHP 8.5+ and Composer 2.x; Swoole is optional and needed only for production workloads.

## Requirements

- **PHP 8.5+** — Nexus uses features introduced in PHP 8.5.
- **Composer 2.x** — for package management.
- **Swoole 5.0+** (optional) — required only if you use `nexus-runtime-swoole`. Not needed for local development with the Fiber runtime.

## Install the meta-package

The `nexus-actors/nexus` meta-package installs `nexus-core`, `nexus-runtime-fiber`, and `nexus-serialization` in one step. This is the right starting point for most projects:

```bash
composer require nexus-actors/nexus
```

## Install individual packages

Install only the packages you need:

```bash
# Core abstractions (actors, behaviors, supervision, mailboxes)
composer require nexus-actors/core

# Fiber runtime (development and testing)
composer require nexus-actors/runtime-fiber

# Swoole runtime (production)
composer require nexus-actors/runtime-swoole

# Step runtime (deterministic testing)
composer require --dev nexus-actors/runtime-step

# Message serialization (wire format via Valinor)
composer require nexus-actors/serialization

# Psalm plugin (static analysis)
composer require --dev nexus-actors/psalm
```

### Swoole extension

The Swoole runtime requires the `ext-swoole` PHP extension. Install it via PECL or your system package manager:

```bash
pecl install swoole
```

Then enable it in your `php.ini`:

```ini
extension=swoole
```

## Verify the installation

After installation, confirm the packages are present:

```bash
composer show nexus-actors/*
```

Run the test suite to confirm everything works:

```bash
vendor/bin/phpunit
```

## Docker setup (for contributors)

The repository ships a Docker Compose configuration with three service targets: `php` (full environment), `php-fiber` (Fiber-only), and `php-swoole` (Swoole-only). A `Makefile` provides shorthand commands.

```bash
make build && make up && make install
```

| Command | Description |
|---|---|
| `make build` | Build Docker images |
| `make up` | Start containers |
| `make down` | Stop containers |
| `make install` | Run `composer install` inside the container |
| `make shell` | Open a bash shell in the PHP container |
| `make test` | Run all tests |
| `make psalm` | Run Psalm static analysis |

## Next steps

- [Quick Start](./quick-start.md) — build your first actor.
- [Key Concepts](./concepts.md) — learn the actor model fundamentals.
- [Runtimes](../runtimes/overview.md) — choose between Fiber and Swoole for your workload.
