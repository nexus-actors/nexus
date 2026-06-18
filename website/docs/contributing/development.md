---
sidebar_position: 1
title: Development
---

# Development

Nexus uses Docker for development to ensure a consistent environment across
all contributors. The project includes a Makefile with targets for all common
operations.

## Docker setup

```bash
make build    # Build Docker images
make up       # Start containers
make install  # Run composer install inside the PHP container
```

To stop the containers:

```bash
make down
```

To open a shell inside the PHP container:

```bash
make shell
```

## Running tests

All tests use PHPUnit 13. The test suites are organised by package and runtime:

```bash
make test                       # Run every suite
make test-unit                  # Unit tests across all packages
make test-fiber                 # Fiber runtime integration tests
make test-swoole                # Swoole runtime integration tests
make test-worker-pool-swoole    # Worker-pool (Swoole threads) integration tests
make test-cluster               # Multi-machine cluster contracts (no remote transport yet)
make test-serialization         # Envelope/message serialization tests
make test-persistence           # Persistence unit + integration tests
make test-doctrine              # Doctrine DBAL pool + ORM pool + EntityBehavior tests
make test-http                  # HTTP layer integration tests
make test-http-swoole           # HTTP + Swoole-threads server integration tests
make mutation                   # Infection mutation testing (min 80% MSI, 90% covered)
```

Swoole-flavoured suites run inside the `php-swoole` Docker container; the rest
run in the default `php` (or `php-fiber`) container.

## Static analysis

Nexus targets Psalm Level 1 (strictest):

```bash
make psalm
```

## Code style

The project uses PHP_CodeSniffer and PHP-CS-Fixer:

```bash
make phpcs    # Check for coding standard violations
make phpcbf   # Automatically fix PHPCS violations
make cs       # Check code style with PHP-CS-Fixer (dry run)
make cs-fix   # Fix code style with PHP-CS-Fixer
```

## Mutation testing

Mutation testing is run with Infection:

```bash
make mutation
```

The minimum thresholds are 80% MSI and 90% covered MSI.

## Project structure

Nexus is organized as a monorepo with a `packages/` directory:

```
nexus/
  packages/
    nexus-core/              # Core abstractions (actors, behaviors, mailboxes, supervision)
      src/
      tests/
    nexus-runtime-fiber/     # Fiber-based runtime
      src/
      tests/
    nexus-runtime-swoole/    # Swoole-based runtime
      src/
      tests/
    nexus-runtime-step/      # Deterministic testing runtime
      src/
      tests/
    nexus-serialization/     # Message serialization
      src/
      tests/
    nexus-psalm/             # Psalm plugin
      src/
  tests/                     # Integration tests
  docs/                      # Design documents and plans
  website/                   # Documentation site
```

All packages share a single root `composer.json`, `phpunit.xml`, and
`psalm.xml`. Autoloading is configured with PSR-4 mappings for each package's
`src/` and `tests/` directories.
