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

All tests use PHPUnit 11. The test suites are organized by package and runtime:

```bash
make test                 # Run all tests
make test-unit            # Unit tests only
make test-fiber           # Fiber runtime integration tests
make test-swoole          # Swoole runtime integration tests (uses php-swoole container)
make test-serialization   # Serialization integration tests
```

The Swoole tests run in a separate `php-swoole` Docker container that has the
Swoole extension installed.

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
