---
title: Your First Pull Request
related:
  - contributing/release-process
  - contributing/adding-a-package
---

# Your First Pull Request

This page walks through contributing a change to Nexus — from cloning the repo to opening a PR.

## Step 1: Clone and build

All development happens inside Docker. No local PHP installation is required.

```bash title="terminal"
git clone https://github.com/nexus-actors/nexus.git
cd nexus
make build      # Build php, php-fiber, and php-swoole images (~3 min first time)
make install    # composer install inside the container
make up         # Start background containers
```

Verify everything is working:

```bash title="terminal"
make test-unit   # Should pass; takes ~30 s
```

## Step 2: Preview the docs

If your change includes documentation, start the Docusaurus dev server:

```bash title="terminal"
cd website
npm install
npm start        # Opens http://localhost:3000
```

The dev server watches `website/docs/**/*.md` and hot-reloads. You do not need the Docker containers running for docs-only changes.

## Step 3: Run only the affected tests

Running the full suite on every iteration is slow. Target just the package you changed:

```bash title="terminal"
# Unit tests for one package
docker compose exec php vendor/bin/phpunit packages/nexus-core/tests/Unit/

# Single test file
docker compose exec php vendor/bin/phpunit \
  packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php

# Single test method
docker compose exec php vendor/bin/phpunit \
  --filter=testMethodName \
  packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php

# Static analysis (must be clean before opening a PR)
make psalm
make phpcs
```

Run the full suite before pushing:

```bash title="terminal"
make test        # All suites
```

## Step 4: Commit with the correct message format

Nexus uses Conventional Commits. The accepted prefixes are:

| Prefix | When to use |
|---|---|
| `feat(scope):` | New feature or new public API |
| `fix(scope):` | Bug fix |
| `docs(scope):` | Documentation-only change |
| `chore(scope):` | Build, CI, dependency updates |
| `refactor(scope):` | Restructuring without behavior change |
| `test(scope):` | Tests only |

The `scope` is the short package name or area: `core`, `http`, `persistence`, `docs`, `ci`, etc.

```bash title="terminal"
# Good
git commit -m "feat(core): add ActorContext::clearStash() to discard buffered messages"
git commit -m "fix(http): return 401 when auth header is present but invalid"
git commit -m "docs(persistence): clarify snapshot retention semantics"

# Bad — no scope, no type, vague
git commit -m "fix stuff"
git commit -m "Update docs"
```

The subject line must be ≤72 characters. The body (after a blank line) is optional but appreciated for non-trivial changes.

Do not add `Co-Authored-By: Claude` or any AI-attribution trailers. The project does not want them.

## Step 5: Open the PR

Push your branch and open a PR against `main`:

```bash title="terminal"
git push -u origin my-feature-branch
gh pr create --title "feat(core): add clearStash()" \
  --body "Adds ActorContext::clearStash() so actors can discard buffered messages during recovery."
```

The CI pipeline runs automatically: lint → static analysis → unit tests → integration tests. All checks must pass before merge.

## What we built

- Clone → Docker build → `make test-unit` verifies setup
- `npm start` for live docs preview
- Run only the affected package tests during iteration
- Conventional commit prefixes: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`

## Next steps

- [Release process](release-process.md) — how packages are split and tagged
- [Adding a package](adding-a-package.md) — checklist for new monorepo packages
- [Adding a runtime](adding-a-runtime.md) — how to contribute a new `Runtime` implementation
