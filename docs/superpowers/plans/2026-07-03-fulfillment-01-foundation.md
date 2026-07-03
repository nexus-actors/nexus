# Nexus Fulfillment — Plan 1: Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bootable skeleton of the `nexus-fulfillment` example: Docker toolchain, SharedKernel value objects, Valinor wire serialization for value-object-carrying contracts, a Swoole HTTP server with health/readiness probes, and all quality gates (PHPUnit, Psalm+plugin, Deptrac, CS-Fixer, PHPCS, CI).

**Architecture:** Standalone Composer project at `examples/nexus-fulfillment/` inside the monorepo (tictactoe/wallet-app pattern: own Dockerfile/compose/Makefile, Nexus packages autoloaded via a read-only `/nexus-packages` volume mount). Modular-monolith DDD layout: bounded contexts arrive in later plans; this plan lays `SharedKernel/` (pure value objects + published contracts) and `Platform/` (composition root, config, HTTP bootstrap).

**Tech Stack:** PHP 8.5 ZTS, Swoole 6.2 (worker/process mode via `SwooleWorkerServer`), nexus-http-ws `WsApplication`, cuyz/valinor via `ValinorMessageSerializer` + `TypeRegistry`, symfony/uid (ULIDs), Postgres 17, PHPUnit 13, Psalm 6 (level 1 + nexus-psalm plugin), Deptrac 4, PHP-CS-Fixer (PER-CS2.0:risky), PHPCS + Slevomat.

**Spec:** `docs/superpowers/specs/2026-07-03-nexus-fulfillment-example-design.md` (this plan implements the "Foundation" slice of the 8-plan series).

## Global Constraints

- **Branch/worktree:** execute in a worktree branched from `main` (branch `feat/fulfillment-example`), created via superpowers:using-git-worktrees. Cherry-pick the spec + plan doc commits onto the branch first (established project workflow).
- **Never run `php`/`composer`/`vendor/bin/*` on the host.** Every command runs through the example's own compose file: `cd examples/nexus-fulfillment && docker compose run --rm app <cmd>` or the Makefile targets. Do NOT use the monorepo root compose for this example.
- **GrumPHP worktree caveat:** the monorepo pre-commit hook is known to fail in worktrees (`env: php: No such file or directory` / wrong compose context). If it fails, commit with `git commit --no-verify` — the example's own `make ci` battery is the quality gate for this plan and must pass before every commit that claims it.
- **Never add `Co-Authored-By: Claude`** (or any Claude attribution) to commits.
- **Code style:** `declare(strict_types=1);` everywhere; all classes `final`; value objects `readonly`; PER-CS2.0; arrays with string keys sorted alphabetically; multi-line ternaries; blank line before `if`/`foreach`/`try` unless it is the first statement in its block; ordered imports (class, function, const — each alphabetical); trailing commas in multiline contexts.
- **Namespace root:** `Monadial\Nexus\Example\Fulfillment\` → `src/`. Tests: `Monadial\Nexus\Example\Fulfillment\Tests\` → `tests/`.
- **Env var prefix:** `FULFILLMENT_`.
- **Versions:** PHP `>=8.5`, `ext-swoole ^6.0`, PHPUnit `^13.0`, Psalm `^6.0`, Deptrac `^4.6`.
- **Ports:** app `9090:8080`, Postgres `5436:5432` (avoid clashes with tictactoe 9080/5434 and wallet-app 8080).
- **TDD:** every behavior lands test-first. YAGNI: build nothing later plans don't consume.

---

### Task 1: Project skeleton and Docker toolchain

**Files:**
- Create: `examples/nexus-fulfillment/composer.json`
- Create: `examples/nexus-fulfillment/Dockerfile`
- Create: `examples/nexus-fulfillment/compose.yaml`
- Create: `examples/nexus-fulfillment/Makefile`
- Create: `examples/nexus-fulfillment/phpunit.xml`
- Create: `examples/nexus-fulfillment/.gitignore`
- Create: `examples/nexus-fulfillment/README.md`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: a buildable container in which `composer install` succeeds and `vendor/bin/phpunit` runs; Makefile targets `build install up down logs shell test psalm deptrac cs cs-fix phpcs ci clean` that every later task uses. Autoload roots `Monadial\Nexus\Example\Fulfillment\` → `src/`, `...\Tests\` → `tests/`.

- [ ] **Step 1: Create the directory and composer.json**

`examples/nexus-fulfillment/composer.json`:

```json
{
    "name": "nexus-actors/example-fulfillment",
    "description": "Order-fulfillment reference application: event-sourced entity actors, a saga with compensation, WebSockets, and full OTel observability on the Nexus actor system.",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "ext-pdo_pgsql": "*",
        "ext-pdo_sqlite": "*",
        "ext-swoole": "^6.0",
        "cuyz/valinor": "^2.3",
        "nikic/fast-route": "^1.3",
        "nyholm/psr7": "^1.8",
        "opis/closure": "^4.0",
        "psr/clock": "^1.0",
        "psr/container": "^2.0",
        "psr/event-dispatcher": "^1.0",
        "psr/http-factory": "^1.1",
        "psr/http-message": "^2.0",
        "psr/http-server-handler": "^1.0",
        "psr/http-server-middleware": "^1.0",
        "psr/log": "^3.0",
        "psr/simple-cache": "^3.0",
        "symfony/cache": "^8.0.12",
        "symfony/uid": "^8.0"
    },
    "require-dev": {
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
        "deptrac/deptrac": "^4.6",
        "friendsofphp/php-cs-fixer": "^3.0",
        "phpunit/phpunit": "^13.0",
        "slevomat/coding-standard": "^8.0",
        "squizlabs/php_codesniffer": "^4.0",
        "vimeo/psalm": "^6.0"
    },
    "autoload": {
        "files": [
            "/nexus-packages/nexus-core/src/Actor/Functions/functions.php"
        ],
        "psr-4": {
            "Monadial\\Nexus\\App\\": "/nexus-packages/nexus-app/src/",
            "Monadial\\Nexus\\Core\\": "/nexus-packages/nexus-core/src/",
            "Monadial\\Nexus\\Example\\Fulfillment\\": "src/",
            "Monadial\\Nexus\\Http\\": "/nexus-packages/nexus-http/src/",
            "Monadial\\Nexus\\Http\\Auth\\": "/nexus-packages/nexus-http-auth/src/",
            "Monadial\\Nexus\\Http\\Server\\Swoole\\": "/nexus-packages/nexus-http-server-swoole/src/",
            "Monadial\\Nexus\\Http\\Server\\Swoole\\Threads\\": "/nexus-packages/nexus-http-server-swoole-threads/src/",
            "Monadial\\Nexus\\Http\\Ws\\": "/nexus-packages/nexus-http-ws/src/",
            "Monadial\\Nexus\\Logger\\": "/nexus-packages/nexus-logger/src/",
            "Monadial\\Nexus\\Observability\\": "/nexus-packages/nexus-observability/src/",
            "Monadial\\Nexus\\Persistence\\": "/nexus-packages/nexus-persistence/src/",
            "Monadial\\Nexus\\Persistence\\Dbal\\": "/nexus-packages/nexus-persistence-dbal/src/",
            "Monadial\\Nexus\\Runtime\\": "/nexus-packages/nexus-runtime/src/",
            "Monadial\\Nexus\\Runtime\\Fiber\\": "/nexus-packages/nexus-runtime-fiber/src/",
            "Monadial\\Nexus\\Runtime\\Step\\": "/nexus-packages/nexus-runtime-step/src/",
            "Monadial\\Nexus\\Runtime\\Swoole\\": "/nexus-packages/nexus-runtime-swoole/src/",
            "Monadial\\Nexus\\Serialization\\": "/nexus-packages/nexus-serialization/src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Example\\Fulfillment\\Tests\\": "tests/",
            "Monadial\\Nexus\\Psalm\\": "/nexus-packages/nexus-psalm/src/"
        }
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        },
        "sort-packages": true
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "scripts": {
        "serve": "php public/server.php",
        "test": "vendor/bin/phpunit"
    }
}
```

Notes for the implementer:
- The `/nexus-packages/...` paths are container-absolute; the compose file mounts `../../packages` there read-only. This is the established example-app pattern (see `examples/nexus-tictactoe/composer.json`) — do NOT convert to path repositories.
- Verify the exact PSR-4 prefixes against each package's own `packages/<name>/composer.json` before finalizing; if a prefix differs (e.g. `Runtime\Fiber`), copy the prefix the package declares.

- [ ] **Step 2: Create the Dockerfile**

`examples/nexus-fulfillment/Dockerfile` (mirrors nexus-tictactoe — PHP 8.5 ZTS + Swoole 6.2.1 with thread support so later plans can switch server modes without an image change):

```dockerfile
# PHP 8.5 ZTS + Swoole 6.2 compiled with --enable-swoole-thread.
# Mirrors the Nexus monorepo's php-swoole image so the example is
# binary-compatible with the monorepo's Swoole runners.
FROM php:8.5.7-zts

RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf build-essential pkg-config \
        libssl-dev libcurl4-openssl-dev libbrotli-dev libnghttp2-dev libpq-dev \
        libsqlite3-dev \
        zlib1g-dev unzip git \
    && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 --branch v6.2.1 https://github.com/swoole/swoole-src.git /tmp/swoole-src \
    && cd /tmp/swoole-src \
    && phpize \
    && ./configure --enable-swoole --enable-swoole-thread --enable-swoole-zlib \
    && make -j"$(nproc)" \
    && make install \
    && rm -rf /tmp/swoole-src \
    && docker-php-ext-enable swoole \
    && echo "swoole.enable_coroutine = On" >> /usr/local/etc/php/conf.d/swoole.ini

# pdo_pgsql for Postgres (runtime), pdo_sqlite for tests.
RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY . /app

EXPOSE 8080

CMD ["php", "public/server.php"]
```

- [ ] **Step 3: Create compose.yaml**

`examples/nexus-fulfillment/compose.yaml`:

```yaml
# Self-contained runtime for the fulfillment example — independent of the
# parent Nexus monorepo's compose.yaml.
#
#   make up      # start the server on :9090
#   make logs    # tail server logs

services:
  app:
    build: .
    image: nexus-fulfillment:dev
    container_name: nexus-fulfillment
    ports:
      - "9090:8080"
    sysctls:
      net.core.somaxconn: 65535
    ulimits:
      nofile:
        soft: 65535
        hard: 65535
    environment:
      FULFILLMENT_DB_HOST: "db"
      FULFILLMENT_DB_NAME: "fulfillment"
      FULFILLMENT_DB_PASS: "fulfillment"
      FULFILLMENT_DB_PORT: "5432"
      FULFILLMENT_DB_USER: "fulfillment"
      FULFILLMENT_HTTP_HOST: "0.0.0.0"
      FULFILLMENT_HTTP_PORT: "8080"
      # Single worker for now: entity actors + the event store's
      # single-writer guarantee need sticky entity->process routing before
      # this can scale out (addressed in a later plan).
      FULFILLMENT_WORKERS: "${FULFILLMENT_WORKERS:-1}"
    depends_on:
      db:
        condition: service_healthy
    volumes:
      - .:/app
      # Path source for the Monadial\Nexus\* autoload mappings.
      - ../../packages:/nexus-packages:ro
    healthcheck:
      test: ["CMD", "php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/healthz') ? 0 : 1);"]
      interval: 5s
      timeout: 2s
      retries: 10

  db:
    image: postgres:17-alpine
    container_name: nexus-fulfillment-db
    environment:
      POSTGRES_DB: "fulfillment"
      POSTGRES_PASSWORD: "fulfillment"
      POSTGRES_USER: "fulfillment"
    ports:
      - "5436:5432"
    volumes:
      - fulfillment-db-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U fulfillment -d fulfillment"]
      interval: 3s
      timeout: 2s
      retries: 20

volumes:
  fulfillment-db-data:
```

- [ ] **Step 4: Create the Makefile**

`examples/nexus-fulfillment/Makefile`:

```makefile
.PHONY: help build install up down logs shell test psalm deptrac cs cs-fix phpcs ci clean

DC := docker compose

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' Makefile | awk 'BEGIN{FS=":.*?## "}{printf "\033[36m%-10s\033[0m %s\n", $$1, $$2}'

build: ## Build the Docker image (PHP 8.5 ZTS + Swoole 6.2)
	$(DC) build

install: ## composer install inside the container
	$(DC) run --rm app composer install --no-interaction --no-progress

up: ## Start the server on :9090 (+ Postgres)
	$(DC) up -d app
	@echo "Server: http://localhost:9090"

down: ## Stop server + Postgres
	$(DC) down

logs: ## Tail server logs
	$(DC) logs -f app

shell: ## Interactive shell inside the app container
	$(DC) run --rm app bash

test: ## Run the PHPUnit suite
	$(DC) run --rm app vendor/bin/phpunit

psalm: ## Psalm static analysis (level 1 + nexus-psalm plugin)
	$(DC) run --rm app vendor/bin/psalm --no-cache

deptrac: ## Deptrac layer + context boundary check
	$(DC) run --rm app php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --no-progress

cs: ## PHP-CS-Fixer dry run
	$(DC) run --rm app vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## PHP-CS-Fixer auto-fix
	$(DC) run --rm app vendor/bin/php-cs-fixer fix

phpcs: ## PHPCS (Slevomat) standards check
	$(DC) run --rm app vendor/bin/phpcs

ci: test psalm deptrac cs phpcs ## Full local quality battery

clean: ## Tear everything down and remove built images
	$(DC) down --rmi local --volumes
```

Note: `psalm`, `deptrac`, `cs`, `phpcs` will fail until Task 5 creates their configs — that is expected; only `make test` participates in Tasks 2–4.

- [ ] **Step 5: Create phpunit.xml, .gitignore, README stub**

`examples/nexus-fulfillment/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`examples/nexus-fulfillment/.gitignore`:

```
/vendor/
/.phpunit.cache/
/.php-cs-fixer.cache
/.deptrac.cache
composer.lock
```

`examples/nexus-fulfillment/README.md`:

```markdown
# nexus-fulfillment

Order-fulfillment reference application for the Nexus actor system —
event-sourced entity actors, a fulfillment saga with compensation,
WebSockets, and full OpenTelemetry observability. Built step by step;
the tutorial lives in `docs/tutorial/` (arrives with later milestones).

**Status: milestone 1 — foundation.** Docker toolchain, SharedKernel
value objects, Valinor wire serialization, Swoole HTTP server with
health probes, quality gates (PHPUnit, Psalm, Deptrac, CS).

## Run it

    make build      # PHP 8.5 ZTS + Swoole 6.2 image
    make install    # composer install inside the container
    make up         # server on http://localhost:9090
    curl localhost:9090/healthz
    curl localhost:9090/readyz

## Quality gates

    make ci         # phpunit + psalm + deptrac + php-cs-fixer + phpcs

This is a standalone Composer project inside the Nexus monorepo — copy
the folder out and `git init` it to use as a starter.
```

- [ ] **Step 6: Build and install**

```bash
cd examples/nexus-fulfillment
make build
make install
docker compose run --rm app composer validate
```

Expected: image builds (Swoole compile takes several minutes), `composer install` resolves and writes `./vendor`, `composer validate` reports `./composer.json is valid`.

- [ ] **Step 7: Commit**

```bash
git add examples/nexus-fulfillment
git commit -m "feat(fulfillment): project skeleton — Docker toolchain, compose, Makefile"
```

---

### Task 2: SharedKernel value objects (TDD)

**Files:**
- Create: `examples/nexus-fulfillment/src/SharedKernel/TenantId.php`
- Create: `examples/nexus-fulfillment/src/SharedKernel/Sku.php`
- Create: `examples/nexus-fulfillment/src/SharedKernel/Quantity.php`
- Create: `examples/nexus-fulfillment/src/SharedKernel/Money.php`
- Create: `examples/nexus-fulfillment/src/SharedKernel/OrderId.php`
- Create: `examples/nexus-fulfillment/src/SharedKernel/OrderLine.php`
- Test: `examples/nexus-fulfillment/tests/Unit/SharedKernel/{TenantIdTest,SkuTest,QuantityTest,MoneyTest,OrderIdTest,OrderLineTest}.php`

**Interfaces:**
- Consumes: Task 1 autoload roots.
- Produces (later plans and Task 3 rely on these exact shapes):
  - `TenantId::__construct(public string $value)` / `TenantId::fromString(string): self` / `equals(self): bool`
  - `Sku::__construct(public string $value)` / `Sku::fromString(string): self` / `equals(self): bool`
  - `Quantity::__construct(public int $value)` / `Quantity::of(int): self` / `equals(self): bool`
  - `Money::__construct(public int $amount, public string $currency)` (amount = minor units) / `Money::of(int, string): self` / `add(self): self` / `multiplyBy(int): self` / `equals(self): bool`
  - `OrderId::__construct(public string $value)` (ULID) / `OrderId::generate(): self` / `OrderId::fromString(string): self` / `equals(self): bool`
  - `OrderLine::__construct(public Sku $sku, public Quantity $quantity, public Money $unitPrice)` / `total(): Money`
  - All constructors are **public** and validating (Valinor maps directly into them); invalid input throws `InvalidArgumentException`.

- [ ] **Step 1: Write failing tests for TenantId, Sku, Quantity**

`tests/Unit/SharedKernel/TenantIdTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantId::class)]
final class TenantIdTest extends TestCase
{
    #[Test]
    public function acceptsLowercaseSlug(): void
    {
        self::assertSame('acme-eu', TenantId::fromString('acme-eu')->value);
    }

    #[Test]
    public function rejectsUppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantId::fromString('ACME');
    }

    #[Test]
    public function rejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantId::fromString('');
    }

    #[Test]
    public function equalsComparesByValue(): void
    {
        self::assertTrue(TenantId::fromString('acme')->equals(TenantId::fromString('acme')));
        self::assertFalse(TenantId::fromString('acme')->equals(TenantId::fromString('umbrella')));
    }
}
```

`tests/Unit/SharedKernel/SkuTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sku::class)]
final class SkuTest extends TestCase
{
    #[Test]
    public function acceptsUppercaseAlphanumericWithDashes(): void
    {
        self::assertSame('WIDGET-42', Sku::fromString('WIDGET-42')->value);
    }

    #[Test]
    public function rejectsLowercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sku::fromString('widget-42');
    }

    #[Test]
    public function rejectsTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sku::fromString('AB');
    }

    #[Test]
    public function equalsComparesByValue(): void
    {
        self::assertTrue(Sku::fromString('WIDGET-42')->equals(Sku::fromString('WIDGET-42')));
        self::assertFalse(Sku::fromString('WIDGET-42')->equals(Sku::fromString('GADGET-7')));
    }
}
```

`tests/Unit/SharedKernel/QuantityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Quantity::class)]
final class QuantityTest extends TestCase
{
    #[Test]
    public function acceptsPositiveInteger(): void
    {
        self::assertSame(3, Quantity::of(3)->value);
    }

    #[Test]
    public function rejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::of(0);
    }

    #[Test]
    public function rejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::of(-1);
    }

    #[Test]
    public function equalsComparesByValue(): void
    {
        self::assertTrue(Quantity::of(2)->equals(Quantity::of(2)));
        self::assertFalse(Quantity::of(2)->equals(Quantity::of(3)));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test`
Expected: FAIL — `Error: Class "Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId" not found` (and same for Sku, Quantity).

- [ ] **Step 3: Implement TenantId, Sku, Quantity**

`src/SharedKernel/TenantId.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

use InvalidArgumentException;

use function preg_match;

/**
 * Identifies a tenant. Every command, event, and entity address in the
 * system carries one — tenants are isolated at the actor level.
 */
final readonly class TenantId
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid tenant id: '{$value}'");
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

`src/SharedKernel/Sku.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

use InvalidArgumentException;

use function preg_match;

/**
 * Stock-keeping unit — the catalog identity of a sellable item.
 */
final readonly class Sku
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{2,31}$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid SKU: '{$value}'");
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

`src/SharedKernel/Quantity.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

use InvalidArgumentException;

/**
 * A strictly positive count of items.
 */
final readonly class Quantity
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException("Quantity must be positive, got {$value}");
        }
    }

    public static function of(int $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test`
Expected: PASS — 12 tests (TenantIdTest 4, SkuTest 4, QuantityTest 4).

- [ ] **Step 5: Commit**

```bash
git add examples/nexus-fulfillment/src/SharedKernel examples/nexus-fulfillment/tests
git commit -m "feat(fulfillment): TenantId, Sku, Quantity value objects"
```

- [ ] **Step 6: Write failing tests for Money**

`tests/Unit/SharedKernel/MoneyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    #[Test]
    public function holdsMinorUnitsAndCurrency(): void
    {
        $price = Money::of(1999, 'EUR');

        self::assertSame(1999, $price->amount);
        self::assertSame('EUR', $price->currency);
    }

    #[Test]
    public function rejectsMalformedCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'eur');
    }

    #[Test]
    public function addsSameCurrency(): void
    {
        $sum = Money::of(1000, 'EUR')->add(Money::of(500, 'EUR'));

        self::assertTrue($sum->equals(Money::of(1500, 'EUR')));
    }

    #[Test]
    public function refusesToAddDifferentCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(1000, 'EUR')->add(Money::of(500, 'USD'));
    }

    #[Test]
    public function multipliesByNonNegativeFactor(): void
    {
        self::assertTrue(Money::of(250, 'EUR')->multiplyBy(4)->equals(Money::of(1000, 'EUR')));
    }

    #[Test]
    public function refusesNegativeFactor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(250, 'EUR')->multiplyBy(-1);
    }

    #[Test]
    public function equalityRequiresAmountAndCurrency(): void
    {
        self::assertTrue(Money::of(100, 'EUR')->equals(Money::of(100, 'EUR')));
        self::assertFalse(Money::of(100, 'EUR')->equals(Money::of(100, 'USD')));
        self::assertFalse(Money::of(100, 'EUR')->equals(Money::of(101, 'EUR')));
    }
}
```

- [ ] **Step 7: Run to verify failure**

Run: `make test`
Expected: FAIL — `Class "...\SharedKernel\Money" not found`.

- [ ] **Step 8: Implement Money**

`src/SharedKernel/Money.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

use InvalidArgumentException;

use function preg_match;

/**
 * An amount of money in minor units (cents) of an ISO-4217 currency.
 * Arithmetic never mixes currencies.
 */
final readonly class Money
{
    public function __construct(public int $amount, public string $currency)
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Invalid currency code: '{$currency}'");
        }
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot add {$other->currency} to {$this->currency}",
            );
        }

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function multiplyBy(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException("Cannot multiply money by negative factor {$factor}");
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
```

- [ ] **Step 9: Run tests, expect green, commit**

Run: `make test`
Expected: PASS — 19 tests.

```bash
git add examples/nexus-fulfillment/src/SharedKernel/Money.php examples/nexus-fulfillment/tests/Unit/SharedKernel/MoneyTest.php
git commit -m "feat(fulfillment): Money value object with currency-safe arithmetic"
```

- [ ] **Step 10: Write failing tests for OrderId and OrderLine**

`tests/Unit/SharedKernel/OrderIdTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderId::class)]
final class OrderIdTest extends TestCase
{
    #[Test]
    public function generatesValidUlid(): void
    {
        $id = OrderId::generate();

        self::assertSame(26, strlen($id->value));
        self::assertTrue($id->equals(OrderId::fromString($id->value)));
    }

    #[Test]
    public function rejectsNonUlid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OrderId::fromString('not-a-ulid');
    }

    #[Test]
    public function generatedIdsAreUnique(): void
    {
        self::assertFalse(OrderId::generate()->equals(OrderId::generate()));
    }
}
```

`tests/Unit/SharedKernel/OrderLineTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderLine::class)]
final class OrderLineTest extends TestCase
{
    #[Test]
    public function totalIsUnitPriceTimesQuantity(): void
    {
        $line = new OrderLine(
            sku: Sku::fromString('WIDGET-42'),
            quantity: Quantity::of(3),
            unitPrice: Money::of(1999, 'EUR'),
        );

        self::assertTrue($line->total()->equals(Money::of(5997, 'EUR')));
    }
}
```

- [ ] **Step 11: Run to verify failure**

Run: `make test`
Expected: FAIL — `Class "...\SharedKernel\OrderId" not found`.

- [ ] **Step 12: Implement OrderId and OrderLine**

`src/SharedKernel/OrderId.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

/**
 * Order identity — a ULID string, sortable by creation time.
 */
final readonly class OrderId
{
    public function __construct(public string $value)
    {
        if (!Ulid::isValid($value)) {
            throw new InvalidArgumentException("Invalid order id: '{$value}'");
        }
    }

    public static function generate(): self
    {
        return new self((string) new Ulid());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

`src/SharedKernel/OrderLine.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

/**
 * One line of an order: what, how many, at what unit price.
 */
final readonly class OrderLine
{
    public function __construct(
        public Sku $sku,
        public Quantity $quantity,
        public Money $unitPrice,
    ) {}

    public function total(): Money
    {
        return $this->unitPrice->multiplyBy($this->quantity->value);
    }
}
```

- [ ] **Step 13: Run tests, expect green, commit**

Run: `make test`
Expected: PASS — 23 tests.

```bash
git add examples/nexus-fulfillment/src/SharedKernel examples/nexus-fulfillment/tests
git commit -m "feat(fulfillment): OrderId and OrderLine value objects"
```

---

### Task 3: Published contract + Valinor wire serialization

**Files:**
- Create: `examples/nexus-fulfillment/src/SharedKernel/Contracts/Orders/OrderPlaced.php`
- Create: `examples/nexus-fulfillment/src/Platform/Serialization/MessageTypes.php`
- Test: `examples/nexus-fulfillment/tests/Unit/Platform/Serialization/MessageSerializationTest.php`

**Interfaces:**
- Consumes: Task 2 value objects; `Monadial\Nexus\Serialization\{MessageType, TypeRegistry, ValinorMessageSerializer}`.
- Produces:
  - `OrderPlaced::__construct(public TenantId $tenantId, public OrderId $orderId, public array $lines, public Money $total)` with `#[MessageType('orders.order_placed.v1')]` — Plan 2's Orders context emits this exact class.
  - `MessageTypes::registry(): TypeRegistry` — the one place contract classes are registered; later plans append to its `CONTRACTS` list. Returns a fresh instance per call (no singletons).
  - Convention locked in: wire names are `{context}.{event_name}.v{N}` — the `.v1` suffix is the seam Plan 7's upcasting uses.

- [ ] **Step 1: Write the failing round-trip test**

`tests/Unit/Platform/Serialization/MessageSerializationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Platform\Serialization;

use Monadial\Nexus\Example\Fulfillment\Platform\Serialization\MessageTypes;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageTypes::class)]
#[CoversClass(OrderPlaced::class)]
final class MessageSerializationTest extends TestCase
{
    #[Test]
    public function orderPlacedSurvivesTheWireWithValueObjectsIntact(): void
    {
        $event = new OrderPlaced(
            tenantId: TenantId::fromString('acme'),
            orderId: OrderId::generate(),
            lines: [
                new OrderLine(Sku::fromString('WIDGET-42'), Quantity::of(3), Money::of(1999, 'EUR')),
                new OrderLine(Sku::fromString('GADGET-7'), Quantity::of(1), Money::of(4900, 'EUR')),
            ],
            total: Money::of(10897, 'EUR'),
        );

        $serializer = new ValinorMessageSerializer(MessageTypes::registry());

        $wire = $serializer->serialize($event);
        $decoded = $serializer->deserialize($wire, 'orders.order_placed.v1');

        self::assertInstanceOf(OrderPlaced::class, $decoded);
        self::assertTrue($decoded->tenantId->equals($event->tenantId));
        self::assertTrue($decoded->orderId->equals($event->orderId));
        self::assertTrue($decoded->total->equals($event->total));
        self::assertCount(2, $decoded->lines);
        self::assertTrue($decoded->lines[0]->sku->equals(Sku::fromString('WIDGET-42')));
        self::assertTrue($decoded->lines[0]->total()->equals(Money::of(5997, 'EUR')));
    }

    #[Test]
    public function registryIsFreshPerCall(): void
    {
        self::assertNotSame(MessageTypes::registry(), MessageTypes::registry());
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `make test`
Expected: FAIL — `Class "...\Platform\Serialization\MessageTypes" not found`.

- [ ] **Step 3: Implement the contract and the registry factory**

`src/SharedKernel/Contracts/Orders/OrderPlaced.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published language: the Orders context announces that a customer placed
 * an order. Other contexts consume this contract — never Orders internals.
 */
#[MessageType('orders.order_placed.v1')]
final readonly class OrderPlaced
{
    /**
     * @param non-empty-list<OrderLine> $lines
     */
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public array $lines,
        public Money $total,
    ) {}
}
```

`src/Platform/Serialization/MessageTypes.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Serialization;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Serialization\TypeRegistry;

/**
 * The single catalog of wire-serializable contract messages. Every
 * published contract carries #[MessageType('{context}.{name}.v{N}')] and
 * is listed here; the version suffix is the upcasting seam.
 */
final class MessageTypes
{
    /** @var list<class-string> */
    private const array CONTRACTS = [
        OrderPlaced::class,
    ];

    public static function registry(): TypeRegistry
    {
        $registry = new TypeRegistry();

        foreach (self::CONTRACTS as $contract) {
            $registry->registerFromAttribute($contract);
        }

        return $registry;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test`
Expected: PASS — 25 tests. If Valinor rejects the nested mapping, check that all value-object constructors are public and promoted (they are the mapping target — `{"value": "acme"}` maps into `TenantId::__construct(string $value)`).

- [ ] **Step 5: Commit**

```bash
git add examples/nexus-fulfillment/src examples/nexus-fulfillment/tests
git commit -m "feat(fulfillment): OrderPlaced contract with Valinor wire serialization"
```

---

### Task 4: Platform bootstrap, health probes, Swoole server

**Files:**
- Create: `examples/nexus-fulfillment/src/Platform/Boot/Env.php`
- Create: `examples/nexus-fulfillment/src/Platform/Boot/HttpConfig.php`
- Create: `examples/nexus-fulfillment/src/Platform/Boot/DbConfig.php`
- Create: `examples/nexus-fulfillment/src/Platform/Boot/FulfillmentConfig.php`
- Create: `examples/nexus-fulfillment/src/Platform/Boot/StderrLogger.php`
- Create: `examples/nexus-fulfillment/src/Platform/Boot/App.php`
- Create: `examples/nexus-fulfillment/src/Platform/Http/ReadinessProbe.php`
- Create: `examples/nexus-fulfillment/src/Platform/Http/Routes.php`
- Create: `examples/nexus-fulfillment/public/server.php`
- Test: `examples/nexus-fulfillment/tests/Unit/Platform/Boot/FulfillmentConfigTest.php`

**Interfaces:**
- Consumes: `WsApplication::create(ActorSystem)` / `->withLogger()` / `->get(path, handler)` / `->compile(): CompiledApplication` (nexus-http-ws); `SwooleWorkerServer::run(SwooleWorkerConfig, Closure(ActorSystem): CompiledApplication)`; `NexusLogger::create(...)` builder (nexus-logger); `Duration::seconds()` (`Monadial\Nexus\Runtime\Duration`).
- Produces:
  - `FulfillmentConfig::fromEnv(): self` with `public HttpConfig $http` (`host`, `port`, `workers`) and `public DbConfig $db` (`host`, `port`, `dbname`, `user`, `password`, `pdoDsn(): string`) — every later plan's boot code extends this.
  - `App::factory(FulfillmentConfig): Closure(ActorSystem): CompiledApplication` — the per-worker composition root later plans grow.
  - `Routes::register(WsApplication $app, ReadinessProbe $probe): void` — later plans add routes here.
  - `StderrLogger::create(string $channel): LoggerInterface` — pre-actor-system logging.
  - HTTP: `GET /healthz` → 200 `{"status":"ok"}`; `GET /readyz` → 200 `{"status":"ready"}` or 503 `{"reason":"...","status":"unready"}`.

- [ ] **Step 1: Write the failing config test**

`tests/Unit/Platform/Boot/FulfillmentConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Platform\Boot;

use Monadial\Nexus\Example\Fulfillment\Platform\Boot\FulfillmentConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function putenv;

#[CoversClass(FulfillmentConfig::class)]
final class FulfillmentConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FULFILLMENT_HTTP_PORT');
        putenv('FULFILLMENT_DB_HOST');
    }

    #[Test]
    public function defaultsAreProductionContainerValues(): void
    {
        $config = FulfillmentConfig::fromEnv();

        self::assertSame('0.0.0.0', $config->http->host);
        self::assertSame(8080, $config->http->port);
        self::assertSame(1, $config->http->workers);
        self::assertSame('db', $config->db->host);
        self::assertSame('pgsql:host=db;port=5432;dbname=fulfillment', $config->db->pdoDsn());
    }

    #[Test]
    public function environmentOverridesDefaults(): void
    {
        putenv('FULFILLMENT_HTTP_PORT=9999');
        putenv('FULFILLMENT_DB_HOST=elsewhere');

        $config = FulfillmentConfig::fromEnv();

        self::assertSame(9999, $config->http->port);
        self::assertSame('elsewhere', $config->db->host);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `make test`
Expected: FAIL — `Class "...\Platform\Boot\FulfillmentConfig" not found`.

- [ ] **Step 3: Implement Env and the config objects**

`src/Platform/Boot/Env.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

use function getenv;

final class Env
{
    /**
     * @param non-empty-string $default
     * @return non-empty-string
     */
    public static function get(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === ''
            ? $default
            : $value;
    }

    public static function int(string $name, int $default): int
    {
        return (int) self::get($name, (string) $default);
    }
}
```

`src/Platform/Boot/HttpConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

final readonly class HttpConfig
{
    public function __construct(public string $host, public int $port, public int $workers) {}

    public static function fromEnv(): self
    {
        return new self(
            host: Env::get('FULFILLMENT_HTTP_HOST', '0.0.0.0'),
            port: Env::int('FULFILLMENT_HTTP_PORT', 8080),
            // Single worker until entity->process routing is sticky; the
            // event store's single-writer guarantee depends on it.
            workers: Env::int('FULFILLMENT_WORKERS', 1),
        );
    }
}
```

`src/Platform/Boot/DbConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

final readonly class DbConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $dbname,
        public string $user,
        public string $password,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            host: Env::get('FULFILLMENT_DB_HOST', 'db'),
            port: Env::int('FULFILLMENT_DB_PORT', 5432),
            dbname: Env::get('FULFILLMENT_DB_NAME', 'fulfillment'),
            user: Env::get('FULFILLMENT_DB_USER', 'fulfillment'),
            password: Env::get('FULFILLMENT_DB_PASS', 'fulfillment'),
        );
    }

    public function pdoDsn(): string
    {
        return "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname}";
    }
}
```

`src/Platform/Boot/FulfillmentConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

final readonly class FulfillmentConfig
{
    public function __construct(public HttpConfig $http, public DbConfig $db) {}

    public static function fromEnv(): self
    {
        return new self(
            http: HttpConfig::fromEnv(),
            db: DbConfig::fromEnv(),
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test`
Expected: PASS — 27 tests.

- [ ] **Step 5: Implement StderrLogger, ReadinessProbe, Routes, App, server.php**

`src/Platform/Boot/StderrLogger.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

use Psr\Log\AbstractLogger;
use Stringable;

use function date;
use function fwrite;
use function json_encode;
use function sprintf;

/**
 * Synchronous stderr logger for the pre-actor-system boot phase. Once the
 * ActorSystem is up, App switches to the async NexusLogger.
 */
final class StderrLogger extends AbstractLogger
{
    private function __construct(private readonly string $channel) {}

    public static function create(string $channel): self
    {
        return new self($channel);
    }

    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $suffix = $context === []
            ? ''
            : ' ' . (string) json_encode($context);

        /** @var resource $stderr */
        $stderr = STDERR;
        fwrite($stderr, sprintf(
            "[%s] %s.%s: %s%s\n",
            date('c'),
            $this->channel,
            (string) $level,
            (string) $message,
            $suffix,
        ));
    }
}
```

`src/Platform/Http/ReadinessProbe.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http;

use Monadial\Nexus\Example\Fulfillment\Platform\Boot\DbConfig;
use PDO;
use PDOException;

/**
 * Readiness = the database answers. Liveness (/healthz) is process-only.
 */
final readonly class ReadinessProbe
{
    public function __construct(private DbConfig $db) {}

    /**
     * @return string|null null when ready, otherwise a short reason
     */
    public function check(): ?string
    {
        try {
            $pdo = new PDO($this->db->pdoDsn(), $this->db->user, $this->db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $pdo->query('SELECT 1');

            return null;
        } catch (PDOException) {
            return 'database unreachable';
        }
    }
}
```

`src/Platform/Http/Routes.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http;

use Monadial\Nexus\Http\Ws\WsApplication;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

use function json_encode;

/**
 * All routes in one place. Later milestones add /api/* and /ws/* here.
 */
final class Routes
{
    public static function register(WsApplication $app, ReadinessProbe $probe): void
    {
        $app->get('/healthz', static fn(): ResponseInterface => self::json(200, ['status' => 'ok']));

        $app->get('/readyz', static function () use ($probe): ResponseInterface {
            $reason = $probe->check();

            return $reason === null
                ? self::json(200, ['status' => 'ready'])
                : self::json(503, ['reason' => $reason, 'status' => 'unready']);
        });
    }

    /**
     * @param array<string, string> $body
     */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Psr7Response(
            $status,
            ['content-type' => 'application/json'],
            (string) json_encode($body),
        );
    }
}
```

`src/Platform/Boot/App.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\ReadinessProbe;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\Routes;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;
use Psr\Log\LoggerInterface;
use Throwable;

use function getmypid;

/**
 * Per-worker application factory — the composition root. Every
 * collaborator is constructed once at worker boot and injected.
 */
final class App
{
    /**
     * @return Closure(ActorSystem): CompiledApplication
     */
    public static function factory(FulfillmentConfig $config): Closure
    {
        return static function (ActorSystem $system) use ($config): CompiledApplication {
            $pid = getmypid();
            $workerId = $pid !== false
                ? $pid
                : 0;
            $preBoot = StderrLogger::create("worker-{$workerId}-preactor");
            $preBoot->info('worker startup: building app');

            try {
                $log = self::asyncLogger($system, $workerId);

                $app = WsApplication::create($system);
                $app->withLogger($log);

                Routes::register($app, new ReadinessProbe($config->db));

                $compiled = $app->compile();
                $log->info('worker startup: app compiled, accepting requests');

                return $compiled;
            } catch (Throwable $e) {
                $preBoot->critical('worker startup failed', [
                    'error' => $e::class . ': ' . $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        };
    }

    private static function asyncLogger(ActorSystem $system, int $workerId): LoggerInterface
    {
        /** @var resource $stderr */
        $stderr = STDERR;

        return NexusLogger::create($system, "worker-{$workerId}")
            ->minLevel(Level::Info)
            ->handler(new ConsoleHandler($stderr, new LineFormatter()))
            ->build();
    }
}
```

`public/server.php`:

```php
<?php

declare(strict_types=1);

/**
 * Fulfillment entry point — boots the Swoole worker server. Worker
 * (process) mode, matching the tictactoe example: WebSocket channel
 * actors (a later milestone) require per-process shared memory.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Monadial\Nexus\Example\Fulfillment\Platform\Boot\App;
use Monadial\Nexus\Example\Fulfillment\Platform\Boot\FulfillmentConfig;
use Monadial\Nexus\Example\Fulfillment\Platform\Boot\StderrLogger;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Runtime\Duration;

$config = FulfillmentConfig::fromEnv();
$log = StderrLogger::create('server');

try {
    SwooleWorkerServer::run(
        SwooleWorkerConfig::bind($config->http->host, $config->http->port)
            ->workers($config->http->workers)
            ->installSignalHandlers(false)
            ->shutdownTimeout(Duration::seconds(5)),
        App::factory($config),
    );
} catch (Throwable $e) {
    $log->critical('server crashed', [
        'error' => $e::class . ': ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);
}
```

- [ ] **Step 6: Run unit tests (still green), then boot and probe**

Run: `make test`
Expected: PASS — 27 tests (nothing in this step is unit-tested beyond config; the probes are verified live).

```bash
make up
sleep 5
curl -s http://localhost:9090/healthz
curl -s http://localhost:9090/readyz
docker compose stop db
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:9090/readyz
docker compose start db
make down
```

Expected: `{"status":"ok"}`, then `{"status":"ready"}`, then `503` while Postgres is stopped. If boot fails, check `make logs` — the most likely cause is a wrong Nexus PSR-4 prefix in composer.json (fix against the package's own composer.json) or a `SwooleWorkerConfig`/`WsApplication` API drift (check `examples/nexus-tictactoe/public/server.php` and `src/Boot/App.php`, which are the reference usage).

- [ ] **Step 7: Commit**

```bash
git add examples/nexus-fulfillment/src examples/nexus-fulfillment/public examples/nexus-fulfillment/tests
git commit -m "feat(fulfillment): platform bootstrap, health probes, Swoole server"
```

---

### Task 5: Quality gates — Psalm, Deptrac, CS-Fixer, PHPCS

**Files:**
- Create: `examples/nexus-fulfillment/psalm.xml`
- Create: `examples/nexus-fulfillment/deptrac.yaml`
- Create: `examples/nexus-fulfillment/.php-cs-fixer.dist.php`
- Create: `examples/nexus-fulfillment/phpcs.xml`

**Interfaces:**
- Consumes: Makefile targets from Task 1; all source from Tasks 2–4.
- Produces: `make ci` green — the gate every later plan runs before every commit. Deptrac layer names (`SharedKernel`, `Domain`, `Application`, `Infrastructure`, `Platform`, `Nexus`) that later plans' contexts must fit into.

- [ ] **Step 1: Create psalm.xml**

```xml
<?xml version="1.0"?>
<psalm
    errorLevel="1"
    findUnusedCode="false"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="https://getpsalm.org/schema/config"
    xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
>
    <projectFiles>
        <directory name="src" />
        <ignoreFiles>
            <directory name="vendor" />
        </ignoreFiles>
    </projectFiles>

    <plugins>
        <pluginClass class="Monadial\Nexus\Psalm\Plugin" />
    </plugins>
</psalm>
```

The nexus-psalm plugin autoloads via the `Monadial\Nexus\Psalm\` dev mapping added in Task 1. It enforces readonly messages, typed `ActorRef<T>` injection, and no blocking calls in handlers — part of what this example teaches.

- [ ] **Step 2: Run Psalm and fix findings**

Run: `make psalm`
Expected: `No errors found!` — if level-1 issues surface in Tasks 2–4 code (e.g. unhandled `int|false`), fix the source, don't suppress. Re-run until clean.

- [ ] **Step 3: Create deptrac.yaml**

```yaml
deptrac:
  paths:
    - src

  layers:
    - name: SharedKernel
      collectors:
        - type: directory
          value: src/SharedKernel/.*

    - name: Domain
      collectors:
        - type: directory
          value: src/[A-Za-z]+/Domain/.*

    - name: Application
      collectors:
        - type: directory
          value: src/[A-Za-z]+/Application/.*

    - name: Infrastructure
      collectors:
        - type: directory
          value: src/[A-Za-z]+/Infrastructure/.*

    - name: Platform
      collectors:
        - type: directory
          value: src/Platform/.*

    - name: Nexus
      collectors:
        - type: classNameRegex
          value: '#^Monadial\\Nexus\\(?!Example\\Fulfillment)#'

  ruleset:
    # Pure domain: PHP + SharedKernel value objects, nothing else.
    Domain: [SharedKernel]
    # SharedKernel touches Nexus ONLY for the #[MessageType] attribute on
    # published contracts.
    SharedKernel: [Nexus]
    Application: [Domain, SharedKernel, Nexus]
    Infrastructure: [Application, Domain, SharedKernel, Nexus]
    Platform: [Application, Domain, Infrastructure, SharedKernel, Nexus]
```

Cross-context isolation rules (Orders must not import Inventory internals) arrive in Plan 3 when a second context exists.

- [ ] **Step 4: Run Deptrac**

Run: `make deptrac`
Expected: `0 Violations` (Domain/Application/Infrastructure layers are empty so far — that's fine). If the config schema is rejected, compare key spelling against the monorepo root `deptrac.yaml` (same Deptrac major version).

- [ ] **Step 5: Create .php-cs-fixer.dist.php and phpcs.xml**

`.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/public',
    ])
    ->name('*.php');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder);
```

`phpcs.xml` (curated Slevomat subset matching the monorepo's signature rules):

```xml
<?xml version="1.0"?>
<ruleset name="Fulfillment">
    <description>nexus-fulfillment coding standard</description>

    <arg name="extensions" value="php" />
    <arg name="colors" />
    <arg value="sp" />

    <file>src</file>
    <file>tests</file>
    <file>public</file>

    <rule ref="SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys" />
    <rule ref="SlevomatCodingStandard.ControlStructures.RequireMultiLineTernaryOperator" />
    <rule ref="SlevomatCodingStandard.ControlStructures.BlockControlStructureSpacing" />
    <rule ref="SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses" />
    <rule ref="SlevomatCodingStandard.Classes.ClassConstantVisibility" />
</ruleset>
```

- [ ] **Step 6: Run both linters and fix findings**

```bash
make cs
make phpcs
```

Expected: both clean. If `make cs` reports diffs, apply with `make cs-fix` and eyeball the changes. Fix PHPCS findings by hand (typical: missing blank line before control structures, unsorted string-key arrays).

- [ ] **Step 7: Run the full battery and commit**

Run: `make ci`
Expected: phpunit 27 tests PASS, Psalm clean, Deptrac 0 violations, CS dry-run clean, PHPCS clean.

```bash
git add examples/nexus-fulfillment
git commit -m "chore(fulfillment): psalm, deptrac, cs-fixer and phpcs quality gates"
```

---

### Task 6: CI workflow and boot smoke test

**Files:**
- Create: `examples/nexus-fulfillment/.github/workflows/ci.yml`

**Interfaces:**
- Consumes: Makefile targets and compose from Task 1; gates from Task 5.
- Produces: the standalone CI companies inherit when they copy the folder out. NOTE: nested workflow files do NOT run inside the monorepo (GitHub only executes `.github/workflows` at repo root) — this file activates when the example is split/copied to its own repo, same as wallet-app's.

- [ ] **Step 1: Create the workflow**

`.github/workflows/ci.yml`:

```yaml
name: ci

on:
  push:
    branches: [main]
  pull_request:

jobs:
  quality:
    name: Lint, static analysis, unit tests
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Build app image
        run: docker compose build app

      - name: Install dependencies
        run: docker compose run --rm app composer install --no-interaction --no-progress

      - name: PHP-CS-Fixer
        run: docker compose run --rm app vendor/bin/php-cs-fixer fix --dry-run --diff

      - name: PHPCS
        run: docker compose run --rm app vendor/bin/phpcs

      - name: Psalm
        run: docker compose run --rm app vendor/bin/psalm --no-cache

      - name: Deptrac
        run: docker compose run --rm app php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --no-progress

      - name: PHPUnit
        run: docker compose run --rm app vendor/bin/phpunit

  smoke:
    name: Boot server and probe health
    runs-on: ubuntu-latest
    needs: quality
    steps:
      - uses: actions/checkout@v4

      - name: Build and install
        run: |
          docker compose build app
          docker compose run --rm app composer install --no-interaction --no-progress

      - name: Boot server
        run: docker compose up -d app

      - name: Wait for /healthz
        run: |
          for i in $(seq 1 30); do
            if curl -fsS http://localhost:9090/healthz > /dev/null; then exit 0; fi
            sleep 1
          done
          docker compose logs app
          exit 1

      - name: Probe /readyz
        run: |
          curl -fsS http://localhost:9090/readyz | tee /tmp/ready.json
          test "$(jq -r .status /tmp/ready.json)" = "ready"

      - name: Tear down
        if: always()
        run: docker compose down
```

IMPORTANT: the workflow runs from the example directory when copied out — but inside the monorepo the compose paths (`../../packages`) only resolve from `examples/nexus-fulfillment/`. No adjustment needed; this file is inert in the monorepo.

- [ ] **Step 2: Validate YAML locally**

Run: `docker compose run --rm app php -r 'exit(0);' && python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml')); print('yaml ok')"` (or any YAML parse check available on the host).
Expected: `yaml ok`.

- [ ] **Step 3: Final full verification**

```bash
make ci
make up
curl -fsS http://localhost:9090/healthz
curl -fsS http://localhost:9090/readyz
make down
```

Expected: full battery green, both probes answer as specified.

- [ ] **Step 4: Commit**

```bash
git add examples/nexus-fulfillment/.github
git commit -m "ci(fulfillment): standalone quality + boot-smoke workflow"
```

---

## Done means

- `make build && make install && make up` from a clean checkout serves `/healthz` and `/readyz` on :9090, with Postgres readiness reflected truthfully.
- `make ci` passes: 27 unit tests, Psalm level 1 + nexus-psalm plugin clean, Deptrac 0 violations, CS-Fixer and PHPCS clean.
- All six SharedKernel value objects and the `OrderPlaced` contract round-trip through `ValinorMessageSerializer` with value objects intact.
- Plan 2 (Orders vertical slice) can start by adding `src/Orders/{Domain,Application,Infrastructure}` into the already-enforced layer rules.
