# Contributing to Nexus

Thank you for considering a contribution to Nexus! This guide will help you get up and running.

## Prerequisites

- **PHP 8.5+**
- **Docker** and **Docker Compose**

All tooling (Composer, PHPUnit, Psalm, etc.) runs inside Docker containers, so you do not need to install them locally.

## Getting Started

```bash
git clone git@github.com:monadial/nexus.git
cd nexus
make build
make install
```

This builds the Docker images and installs Composer dependencies.

## Development Workflow

1. Create a feature branch from `main`.
2. Write tests first -- Nexus follows a test-driven approach.
3. Implement your changes.
4. Run the full check suite before pushing (see below).
5. Open a pull request against `main`.

## Code Style

Nexus uses both PHP_CodeSniffer and PHP-CS-Fixer:

| Command         | Description                    |
|-----------------|--------------------------------|
| `make phpcs`    | Check coding standards (PHPCS) |
| `make phpcbf`   | Auto-fix PHPCS violations      |
| `make cs`       | Check code style (CS-Fixer)    |
| `make cs-fix`   | Auto-fix code style (CS-Fixer) |

## Static Analysis

Nexus uses Psalm at Level 1 with a custom plugin for actor type inference:

```bash
make psalm
```

## Testing

| Command          | Description     |
|------------------|-----------------|
| `make test`      | Run all tests   |
| `make test-unit` | Unit tests only |

## Pull Request Expectations

- **One concern per PR.** Keep changes focused on a single feature, fix, or refactoring.
- **Descriptive title.** Use a clear, concise title that summarizes the change.
- **Passing CI required.** All checks (tests, PHPCS, Psalm) must pass before a PR can be merged.
- **Include tests.** New features and bug fixes should come with corresponding tests.
- **Update documentation** if your change affects public APIs or user-facing behavior.
