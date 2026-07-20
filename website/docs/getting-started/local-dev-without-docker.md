---
sidebar_position: 6
title: Local dev without Docker
related:
  - getting-started/installation
  - getting-started/module-selector
  - runtimes/overview
  - getting-started/quick-start
---

# Local dev without Docker

This page covers running Nexus on your host machine without Docker — useful for quick spikes, CI environments without Docker, or editors where debugging through containers adds friction. Docker remains the recommended path for full feature parity; some features are Docker-only.

:::note Docker is recommended
The Docker setup (`make build && make up`) gives you Xdebug, Swoole, ZTS PHP, and pre-configured services out of the box. Use the host-PHP path only when Docker is not available or you want a faster feedback loop for Fiber-only work.
:::

## What works without Docker

| Feature | Host PHP | Docker |
|---|---|---|
| Actor system (Fiber runtime) | Yes | Yes |
| Deterministic tests (Step runtime) | Yes | Yes |
| Persistence (in-memory store) | Yes | Yes |
| Xdebug | Yes (if installed) | Yes |
| Swoole runtime | No — Swoole build is non-trivial | Yes |
| Worker pool (Swoole threads) | No — requires ZTS PHP + Swoole 6 | Yes |

## macOS setup

### Step 1: Install PHP 8.5

Homebrew ships PHP 8.5 via the `shivammathur/php` tap:

```bash
brew tap shivammathur/php
brew install shivammathur/php/php@8.5
brew link --overwrite --force php@8.5
```

Verify:

```bash
php --version   # PHP 8.5.x
```

### Step 2: Install Composer

```bash
brew install composer
composer --version   # Composer 2.x
```

### Step 3: Install Nexus

Create a new project directory and require the meta-package:

```bash
mkdir my-actor-app && cd my-actor-app
composer require nexus-actors/nexus
```

This installs `nexus-core`, `nexus-runtime-fiber`, and `nexus-serialization`.

### Step 4: Write a demo actor

Create a `demo.php` that spawns a greeter actor and sends one message:

<!-- verify:skip: requires a running actor system -->
```php title="demo.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

require 'vendor/autoload.php';

readonly class Greet
{
    public function __construct(public string $name) {}
}

$runtime = new FiberRuntime();
$system  = ActorSystem::create('demo', $runtime);

$behavior = Behavior::receive(static function ($ctx, object $msg): Behavior {
    if ($msg instanceof Greet) {
        echo 'Hello, ' . $msg->name . PHP_EOL;
    }

    return Behavior::same();
});

$ref = $system->spawn(Props::fromBehavior($behavior), 'greeter');
$ref->tell(new Greet('world'));
$runtime->scheduleOnce(Duration::millis(100), fn () => $system->shutdown(Duration::seconds(1)));
$system->run();
```

### Step 5: Run it

```bash
php demo.php
# Hello, world
```

## Linux (Debian/Ubuntu) setup

Install the `ondrej/php` PPA which ships PHP 8.5:

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y php8.5-cli php8.5-mbstring php8.5-curl php8.5-zip
```

Then follow Steps 2–5 from the macOS path above.

## Caveats

The following require the Docker environment and cannot be set up on a standard host PHP install:

- **Swoole runtime** — Swoole requires compilation from source or a pre-built PECL package. Even if installed, the worker pool features require ZTS PHP, which Homebrew and apt do not ship.
- **ZTS PHP + Swoole threads** — The `nexus-worker-pool-swoole` package requires `php-zts` 8.5+ and Swoole 6.2.1+ compiled with `--enable-swoole-thread`. No standard package manager ships this combination. Use `docker compose exec php-swoole` for worker pool work.
- **Xdebug + Swoole** — Xdebug and Swoole are incompatible in the same PHP process (both override `zend_execute_ex()`). The Docker setup keeps them on separate containers (`php-fiber` for Xdebug, `php-swoole` for Swoole) to avoid this.

## Next steps

- [Module selector](./module-selector.md) — pick the right packages for your use case.
- [Installation](./installation.md) — smoke-test your setup.
- [Runtimes overview](../runtimes/overview.md) — understand Fiber vs Swoole tradeoffs.
