---
title: nexus-skeleton (project template)
related:
  - packages/nexus
  - getting-started/installation
  - getting-started/quick-start
---

# nexus-skeleton (project template)

A Composer **project template** that scaffolds a new Nexus application in one command, with your chosen runtime, HTTP integration, persistence backend (DBAL or Doctrine ORM), and observability wired up. Think of it as `symfony new my-app` for Nexus.

## Install

```bash title="terminal"
composer create-project nexus-actors/skeleton my-app
```

Composer downloads the skeleton, then runs an interactive post-install script that asks about your target runtime and integrations. When you answer, the script trims unused packages from `composer.json`, assembles a `bootstrap.php` that matches your choices, removes the skeleton scaffolding, and re-runs `composer update` so `vendor/` reflects only what you picked.

## Alternative: the web configurator

If you'd rather pick your choices in a UI before running any command, the [bootstrap configurator on nexusactors.com](https://nexusactors.com/bootstrap) produces the exact `NEXUS_RUNTIME=... NEXUS_HTTP=... composer create-project --no-interaction ...` command for a POSIX shell (Bash/Zsh). Windows users should set the env vars separately before running `composer create-project`.

## What you get

The scaffolded application ships with:

```
my-app/
├── composer.json                     # trimmed to your selections
├── bootstrap.php                     # assembled from the runtime/HTTP/persistence partials
├── docker-compose.yml                # base app service
├── docker-compose.swoole.yml         # kept when runtime = swoole
├── docker-compose.worker-pool.yml    # kept when runtime = worker-pool
├── docker-compose.db.yml             # kept when persistence ≠ none
├── src/
│   ├── Actor/ExampleActor.php        # starter ping/pong actor
│   ├── Message/{Ping,Pong}.php       # typed messages
│   ├── Http/                         # only if HTTP was chosen
│   │   ├── HelloController.php
│   │   └── routes.php
│   └── Persistence/                  # only if persistence was chosen
│       └── ExampleStateActor.php
├── tests/
│   └── ExampleActorTest.php          # StepRuntime + VirtualClock deterministic test
├── phpunit.xml.dist
├── psalm.xml
└── README.md
```

Everything is a starting point, not a lock-in — extend, replace, or delete as your application grows.

## Choices the installer asks about

| Prompt | Values | Env-var override |
|---|---|---|
| Runtime | `fiber` (default) · `swoole` · `worker-pool` | `NEXUS_RUNTIME` |
| HTTP server | yes / no (default no) | `NEXUS_HTTP=1` |
| Persistence | `none` (default) · `es-dbal` · `es-doctrine` · `durable-dbal` · `durable-doctrine` — the `*-doctrine` values wire the Doctrine ORM bridge; `*-dbal` values use Doctrine DBAL | `NEXUS_PERSISTENCE` |
| OpenTelemetry tracing | yes / no (default no) | `NEXUS_OTEL=1` |
| TCP cluster mesh (Swoole only) | yes / no (default no) | `NEXUS_CLUSTER=1` |
| Symfony Messenger bridge | yes / no (default no) | `NEXUS_MESSENGER=1` |

## Non-interactive install

Pipe the env vars for a scripted install:

```bash title="terminal — non-interactive"
NEXUS_RUNTIME=swoole NEXUS_HTTP=1 NEXUS_PERSISTENCE=es-dbal \
    composer create-project --no-interaction nexus-actors/skeleton my-app
```

Composer's `--no-interaction` skips all prompts; the installer reads the env vars and applies the same trimming + assembly steps.

## After install

The skeleton ships up to three compose files. The installer removes the ones that don't apply, so after `create-project` you start only the files that remain in your project root.

**Fiber (no persistence)** — only `docker-compose.yml` survives:

```bash title="terminal"
cd my-app
docker compose up -d
docker compose exec app php bootstrap.php
```

**Swoole (no persistence)** — `docker-compose.yml` + `docker-compose.swoole.yml`:

```bash title="terminal"
cd my-app
docker compose -f docker-compose.yml -f docker-compose.swoole.yml up -d
docker compose -f docker-compose.yml -f docker-compose.swoole.yml exec app php bootstrap.php
```

**Worker pool (no persistence)** — `docker-compose.yml` + `docker-compose.worker-pool.yml`:

```bash title="terminal"
cd my-app
docker compose -f docker-compose.yml -f docker-compose.worker-pool.yml up -d
docker compose -f docker-compose.yml -f docker-compose.worker-pool.yml exec app php bootstrap.php
```

**Any runtime + persistence** — add `-f docker-compose.db.yml` to the above for the Postgres service:

```bash title="terminal"
cd my-app
# example: swoole + es-dbal
docker compose -f docker-compose.yml -f docker-compose.swoole.yml -f docker-compose.db.yml up -d
docker compose -f docker-compose.yml -f docker-compose.swoole.yml -f docker-compose.db.yml exec app php bootstrap.php
# example: worker-pool + es-dbal
docker compose -f docker-compose.yml -f docker-compose.worker-pool.yml -f docker-compose.db.yml up -d
docker compose -f docker-compose.yml -f docker-compose.worker-pool.yml -f docker-compose.db.yml exec app php bootstrap.php
```

To run the starter test suite:

```bash title="terminal"
docker compose exec app vendor/bin/phpunit
```

## Runtime notes

- **Fiber** — single process on PHP Fibers. Perfect for local development and small services. No extension requirements beyond PHP 8.5+.
- **Swoole** — coroutines + true async I/O in one process. Uses `phpswoole/swoole:6.2-php8.5-alpine` (NTS) in `docker-compose.swoole.yml`. Requires the Swoole extension.
- **Worker pool** — N Swoole threads sharing a `Thread\Queue` hash ring. Requires ZTS PHP and Swoole compiled with `--enable-swoole-thread`. The skeleton pins `phpswoole/swoole:6.2.1-php8.5-zts` in `docker-compose.worker-pool.yml`, so no image swap is needed.

## Persistence notes

- **Event sourcing (DBAL)** — appends events to a Doctrine DBAL 4 store. `bootstrap.php` wires a `DriverManager::getConnection(DsnParser::parse($_ENV['DATABASE_URL']))` connection into `DbalEventStore` and `DbalSnapshotStore`.
- **Event sourcing (Doctrine ORM)** — same event-sourced model but uses your existing `EntityManager`, so events share connections + transactions with the rest of your ORM code.
- **Durable state (DBAL / Doctrine)** — persists full state snapshots via `DbalDurableStateStore` / `DoctrineDurableStateStore` instead of an event log. Simpler than event sourcing when you don't need history.

Set `DATABASE_URL` in your environment (or `docker-compose.yml`) before running `bootstrap.php`:

```bash title="terminal"
export DATABASE_URL='postgres://nexus:nexus@db:5432/nexus'
```

The included `docker-compose.db.yml` supplies a `postgres:16-alpine` service with these defaults when a persistence backend is selected.

## Version and stability

The skeleton pins to the same PHP floor (`>=8.5.7`), PHPUnit (`^13`), and Psalm (`^6`) as the monorepo. Its own version tracks the last stable Nexus release; upgrades are additive and non-breaking.
