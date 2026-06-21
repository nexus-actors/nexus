---
sidebar_position: 1
title: Development
related:
  - contributing/roadmap
  - architecture/design-philosophy
  - architecture/internals
---

# Development

All development happens inside Docker. No local PHP installation is required — every command (tests, linting, Psalm, Composer) runs through `docker compose exec php` or via `make` targets.

## Setup

```bash
make build    # Build Docker images (php, php-fiber, php-swoole)
make install  # Run composer install inside the PHP container
make up       # Start containers
make down     # Stop containers
make shell    # Open a bash shell in the PHP container
```

## Docker services

Three services are defined in `docker-compose.yml`:

| Service | Purpose |
|---|---|
| `php` | Full environment (Xdebug + Swoole) for interactive development |
| `php-fiber` | Fiber-only for unit, integration tests, and CI |
| `php-swoole` | Swoole-only for Swoole and worker-pool tests and CI |

## Running tests

All tests use PHPUnit 13. The test suites are organized by package and runtime:

```bash
make test                       # Every suite
make test-unit                  # Unit tests across all packages
make test-fiber                 # Fiber runtime integration tests
make test-swoole                # Swoole runtime integration tests
make test-worker-pool-swoole    # Worker-pool (Swoole threads) integration tests
make test-cluster               # Cluster contracts tests
make test-serialization         # Envelope/message serialization tests
make test-persistence           # Persistence unit + integration tests
make test-doctrine              # Doctrine DBAL pool + ORM pool + EntityBehavior tests
make test-http                  # HTTP layer integration tests
make test-http-swoole           # HTTP + Swoole-threads server integration tests
```

Swoole-flavoured suites run inside the `php-swoole` Docker container; the rest run in the default `php` (or `php-fiber`) container.

To run a single test file or method:

```bash
docker compose exec php vendor/bin/phpunit \
    packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php

docker compose exec php vendor/bin/phpunit \
    --filter=testMethodName \
    packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php
```

## Static analysis

Nexus targets Psalm Level 1 (strictest):

```bash
make psalm    # Psalm type checking
```

## Code style

The project enforces PER-CS2.0 with Slevomat extensions via PHP_CodeSniffer and PHP-CS-Fixer:

```bash
make phpcs    # Check for coding standard violations
make phpcbf   # Auto-fix PHPCS violations
make cs       # Check code style with PHP-CS-Fixer (dry run)
make cs-fix   # Fix code style with PHP-CS-Fixer
```

## Mutation testing

Infection runs with minimum thresholds of 80% MSI and 90% covered MSI:

```bash
make mutation
```

## Pre-commit hooks

GrumPHP runs four checks on every commit via Docker:

1. **PHP-CS-Fixer** — Code style (PER-CS2.0:risky)
2. **PHPCS** — Coding standards (Slevomat extensions)
3. **Psalm** — Static type analysis (level 1)
4. **PHPUnit** — Unit test suite

All four must pass before the commit is accepted.

## Project structure

Nexus is organized as a monorepo with a `packages/` directory. All packages share a single root `composer.json`, `phpunit.xml`, and `psalm.xml`:

```
nexus/
  packages/
    nexus-core/              # Actors, behaviors, mailboxes, supervision
    nexus-runtime-fiber/     # Fiber-based runtime
    nexus-runtime-swoole/    # Swoole-based runtime
    nexus-runtime-step/      # Deterministic testing runtime
    nexus-serialization/     # Message serialization
    nexus-psalm/             # Psalm plugin
    …                        # One directory per package
  tests/                     # Integration tests
  docs/                      # Design documents and plans
  website/                   # Documentation site
```

When modifying dev dependency versions (e.g., PHPUnit), update both the root `composer.json` and all `packages/*/composer.json` files — each package is published independently to Packagist.

## See also

- [Roadmap](./roadmap.md) — what is shipped, in progress, and planned.
- [Design philosophy](../architecture/design-philosophy.md) — the principles that drive architectural decisions.
