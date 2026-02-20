---
sidebar_position: 1
title: Installation
---

# Installation

## Requirements

- **PHP 8.5+** -- Nexus uses features introduced in PHP 8.5.
- **Swoole 5.0+** (optional) -- required only if you use the `nexus-runtime-swoole` package for production workloads. Not needed for local development with the Fiber runtime.
- **Composer 2.x** -- for package management.

## Composer packages

### Meta-package (recommended for most projects)

The `nexus-actors/nexus` meta-package installs `nexus-core`, `nexus-runtime-fiber`,
and `nexus-serialization` in one step:

```bash
composer require nexus-actors/nexus
```

### Individual packages

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

### Swoole runtime dependency

The Swoole runtime requires the `ext-swoole` PHP extension. Install it via PECL
or your system package manager:

```bash
pecl install swoole
```

Then enable it in your `php.ini`:

```ini
extension=swoole
```

## Docker setup

The project ships a Docker Compose configuration with three service targets:
`php` (full environment), `php-fiber` (Fiber-only), and `php-swoole`
(Swoole-only). A `Makefile` provides shorthand commands.

### Build and start

```bash
make build && make up && make install
```

This builds the Docker images, starts the containers in the background, and runs
`composer install` inside the PHP container.

### Common make targets

| Command | Description |
|---|---|
| `make build` | Build Docker images |
| `make up` | Start containers |
| `make down` | Stop containers |
| `make install` | Run `composer install` inside the container |
| `make shell` | Open a bash shell in the PHP container |
| `make test` | Run all tests |
| `make test-unit` | Run unit tests only |
| `make test-fiber` | Run Fiber integration tests |
| `make test-swoole` | Run Swoole integration tests |
| `make psalm` | Run Psalm static analysis |

## Verification

After installation, verify that everything works by running the test suite:

```bash
# Inside Docker
make test

# Or directly with Composer
vendor/bin/phpunit
```

You can also confirm the packages are installed:

```bash
composer show nexus-actors/*
```

This should list the Nexus packages along with their installed versions.

## Next steps

- [Quick Start](./quick-start.md) -- build your first actor.
- [Key Concepts](./concepts.md) -- learn the actor model fundamentals.
