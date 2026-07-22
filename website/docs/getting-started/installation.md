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
- **Swoole 6.2.1+** (optional) — `nexus-runtime-swoole` requires `ext-swoole >= 6.2.1`. Not needed for local development with the Fiber runtime.

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
| `make test` | Run only the suites that fit on the `php` container (unit + Fiber/HTTP/Step/serialization/messenger/persistence integration) — **not** comprehensive |
| `make test-all` | Run the full correctness matrix across both the `php` and `php-swoole` containers |
| `make psalm` | Run Psalm static analysis |

`make test` is **not** every suite: Swoole, worker-pool, cluster, HTTP-Swoole, and Doctrine-Swoole integration tests run on the `php-swoole` container via dedicated targets (`make test-swoole`, `make test-worker-pool-swoole`, `make test-cluster`, `make test-http-swoole`, `make test-doctrine`), and performance benchmarks have their own `perf-*` targets. Run **`make test-all`** to execute the whole correctness matrix (everything except the perf benchmarks) with a single command, or `make help` for the full list of targets.

## Smoke test

Run this script after installation to confirm the actor system, Fiber runtime, and message delivery all work end-to-end. If it prints `OK`, your install is healthy.

<!-- verify:skip: requires a running actor system -->
```php title="smoke.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

require 'vendor/autoload.php';

readonly class Increment {}
readonly class GetCount { public function __construct(public object $replyTo) {} }

$captured = null;
$runtime  = new FiberRuntime();
$system   = ActorSystem::create('smoke', $runtime);

$counter = $system->spawn(
    Props::fromBehavior(Behavior::withState(
        0,
        static function ($ctx, object $msg, int $n) use (&$captured): BehaviorWithState {
            if ($msg instanceof Increment) {
                return BehaviorWithState::next($n + 1);
            }
            if ($msg instanceof GetCount) {
                $captured = $n;
            }
            return BehaviorWithState::same();
        },
    )),
    'counter',
);

$counter->tell(new Increment());
$counter->tell(new Increment());
$counter->tell(new Increment());
$counter->tell(new GetCount($system->deadLetters()));
$runtime->scheduleOnce(Duration::millis(200), fn () => $system->shutdown(Duration::seconds(1)));
$system->run();

echo $captured === 3 ? 'OK' : 'FAIL: expected 3, got ' . $captured;
echo PHP_EOL;
```

Run the smoke test:

```bash
php smoke.php
# OK
```

## Next steps

- [Quick Start](./quick-start.md) — build your first actor.
- [Key Concepts](./concepts.md) — learn the actor model fundamentals.
- [Runtimes](../runtimes/overview.md) — choose between Fiber and Swoole for your workload.
