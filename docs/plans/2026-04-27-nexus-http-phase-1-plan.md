# nexus-http Phase 1 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the runtime-agnostic `nexus-http` library + `nexus-http-testkit` + `nexus-http-swoole` T3 dev server, with the closure-nested directive DSL, type-safe extractors, content-negotiated marshalling, default error mapping, three baseline middlewares, and a working orders-CRUD example end-to-end.

**Architecture:** Three packages.
- `nexus-http` (runtime-agnostic): directives as composable `Route` values, evaluated as a function from `RequestCtx` to `?ResponseInterface`. Built on PSR-7/15/17. `MarshallerRegistry` registers content-type marshallers; `JsonValinorMarshaller` is the default. `DispatchTrie` compiles `concat()` trees into a method-keyed prefix tree at boot.
- `nexus-http-testkit` (no Swoole): synthetic PSR-7 request builders, `RouteTestKit` for route-level tests, `withSystem()` for actor-aware tests via `StepRuntime`.
- `nexus-http-swoole`: T3 only this phase — `HttpServerBootstrap::dev()` constructor, single coroutine, single `ActorSystem`, `Swoole\Http\Server` in `SWOOLE_BASE` mode.

**Tech Stack:** PHP 8.5+, PSR-7/15/17 (`psr/http-message`, `psr/http-server-handler`, `psr/http-server-middleware`, `psr/http-factory`), `nyholm/psr7`, `cuyz/valinor`, `symfony/uid`, Swoole 6.0 (T3 only this phase), PHPUnit 13.

**Design doc:** `docs/plans/2026-04-27-nexus-http-design.md`

---

## Setup: Create worktree

```bash
git worktree add .worktrees/feat/nexus-http -b feat/nexus-http
cd .worktrees/feat/nexus-http
make build && make install
```

All subsequent work happens inside this worktree. Run all `composer`, `phpunit`, `psalm` etc. via `make` or `docker compose exec php ...` per `CLAUDE.md`.

---

## Task 1: Package scaffold — nexus-http

**Files:**
- Create: `packages/nexus-http/composer.json`
- Create: `packages/nexus-http/src/.gitkeep`
- Create: `packages/nexus-http/tests/Unit/.gitkeep`
- Modify: `composer.json` (root) — add autoload entries, runtime deps
- Modify: `phpunit.xml` — add unit testsuite entry
- Modify: `deptrac.yaml` — add `Http` layer
- Modify: `infection.json5` — add path

- [ ] **Step 1: Create `packages/nexus-http/composer.json`**

```json
{
    "name": "nexus-actors/http",
    "description": "Nexus HTTP — Akka HTTP-style composable directive DSL on PSR-7/15/17.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "cuyz/valinor": "^2.3",
        "nexus-actors/core": "dev-main",
        "nexus-actors/serialization": "dev-main",
        "nyholm/psr7": "^1.8",
        "psr/http-factory": "^1.0",
        "psr/http-message": "^2.0",
        "psr/http-server-handler": "^1.0",
        "psr/http-server-middleware": "^1.0",
        "psr/log": "^3.0",
        "symfony/uid": "^8.0"
    },
    "require-dev": {
        "nexus-actors/runtime-step": "dev-main",
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "files": [
            "src/Directive/functions.php"
        ],
        "psr-4": {
            "Monadial\\Nexus\\Http\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Http\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Add to root `composer.json`**

In `require` add:
```json
"psr/http-factory": "^1.0",
"psr/http-message": "^2.0",
"psr/http-server-handler": "^1.0",
"psr/http-server-middleware": "^1.0",
"nyholm/psr7": "^1.8",
```

In `autoload.files` (create the array if it doesn't include this) add:
```json
"packages/nexus-http/src/Directive/functions.php"
```

In `autoload.psr-4` (alphabetical):
```json
"Monadial\\Nexus\\Http\\": "packages/nexus-http/src/",
```

In `autoload-dev.psr-4`:
```json
"Monadial\\Nexus\\Http\\Tests\\": "packages/nexus-http/tests/",
```

- [ ] **Step 3: Add to `phpunit.xml`**

In the `unit` testsuite block add the directory (alphabetical):
```xml
<directory>packages/nexus-http/tests/Unit</directory>
```

In `<source><include>`:
```xml
<directory>packages/nexus-http/src</directory>
```

- [ ] **Step 4: Add to `deptrac.yaml`**

In the `layers:` list, after the `Ddd` entry:
```yaml
- name: Http
  collectors:
    - type: directory
      value: packages/nexus-http/src/.*
```

In the `ruleset:` block add:
```yaml
Http:
  - Core
  - Serialization
```

- [ ] **Step 5: Verify scaffold**

Run: `make install && make psalm`
Expected: composer install succeeds; psalm reports 0 errors (no source files yet).

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http composer.json composer.lock phpunit.xml deptrac.yaml
git commit -m "scaffold(http): nexus-http package skeleton"
```

---

## Task 2: Package scaffold — nexus-http-testkit

**Files:**
- Create: `packages/nexus-http-testkit/composer.json`
- Create: `packages/nexus-http-testkit/src/.gitkeep`
- Create: `packages/nexus-http-testkit/tests/Unit/.gitkeep`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`
- Modify: `deptrac.yaml`

- [ ] **Step 1: Create `packages/nexus-http-testkit/composer.json`**

```json
{
    "name": "nexus-actors/http-testkit",
    "description": "Nexus HTTP test kit — pure-PHP route testing without booting a server.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "nexus-actors/core": "dev-main",
        "nexus-actors/http": "dev-main",
        "nexus-actors/runtime-step": "dev-main",
        "nyholm/psr7": "^1.8",
        "psr/http-message": "^2.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Http\\TestKit\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Http\\TestKit\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Add to root `composer.json`**

In `autoload.psr-4`:
```json
"Monadial\\Nexus\\Http\\TestKit\\": "packages/nexus-http-testkit/src/",
```

In `autoload-dev.psr-4`:
```json
"Monadial\\Nexus\\Http\\TestKit\\Tests\\": "packages/nexus-http-testkit/tests/",
```

- [ ] **Step 3: Add to `phpunit.xml`**

In the `unit` testsuite:
```xml
<directory>packages/nexus-http-testkit/tests/Unit</directory>
```

In `<source><include>`:
```xml
<directory>packages/nexus-http-testkit/src</directory>
```

- [ ] **Step 4: Add to `deptrac.yaml`**

```yaml
- name: HttpTestKit
  collectors:
    - type: directory
      value: packages/nexus-http-testkit/src/.*
```

In `ruleset:`:
```yaml
HttpTestKit:
  - Core
  - Http
  - RuntimeStep
```

- [ ] **Step 5: Verify**

Run: `make install`
Expected: composer install succeeds.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http-testkit composer.json composer.lock phpunit.xml deptrac.yaml
git commit -m "scaffold(http-testkit): nexus-http-testkit package skeleton"
```

---

## Task 3: Package scaffold — nexus-http-swoole

**Files:**
- Create: `packages/nexus-http-swoole/composer.json`
- Create: `packages/nexus-http-swoole/src/.gitkeep`
- Create: `packages/nexus-http-swoole/tests/Unit/.gitkeep`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`
- Modify: `deptrac.yaml`

- [ ] **Step 1: Create `packages/nexus-http-swoole/composer.json`**

```json
{
    "name": "nexus-actors/http-swoole",
    "description": "Nexus HTTP Swoole runtime — single-coroutine and threaded server bootstrap.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "ext-swoole": "*",
        "nexus-actors/core": "dev-main",
        "nexus-actors/http": "dev-main",
        "nexus-actors/runtime-swoole": "dev-main",
        "nexus-actors/worker-pool-swoole": "dev-main",
        "psr/http-message": "^2.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Http\\Swoole\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Http\\Swoole\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Add to root `composer.json`**

In `autoload.psr-4`:
```json
"Monadial\\Nexus\\Http\\Swoole\\": "packages/nexus-http-swoole/src/",
```

In `autoload-dev.psr-4`:
```json
"Monadial\\Nexus\\Http\\Swoole\\Tests\\": "packages/nexus-http-swoole/tests/",
```

- [ ] **Step 3: Add to `phpunit.xml`**

In the `unit-swoole` testsuite:
```xml
<directory>packages/nexus-http-swoole/tests/Unit</directory>
```

In `<source><include>`:
```xml
<directory>packages/nexus-http-swoole/src</directory>
```

- [ ] **Step 4: Add to `deptrac.yaml`**

```yaml
- name: HttpSwoole
  collectors:
    - type: directory
      value: packages/nexus-http-swoole/src/.*
```

In `ruleset:`:
```yaml
HttpSwoole:
  - Core
  - Http
  - RuntimeSwoole
  - WorkerPoolSwoole
```

- [ ] **Step 5: Verify**

Run: `make install && make psalm`
Expected: composer install succeeds.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http-swoole composer.json composer.lock phpunit.xml deptrac.yaml
git commit -m "scaffold(http-swoole): nexus-http-swoole package skeleton"
```

---

## Task 4: `Route` value object + tests

**Files:**
- Create: `packages/nexus-http/src/Routing/Route.php`
- Create: `packages/nexus-http/tests/Unit/Routing/RouteTest.php`

- [ ] **Step 1: Write failing test `packages/nexus-http/tests/Unit/Routing/RouteTest.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Closure;
use Monadial\Nexus\Http\RequestCtx;
use Monadial\Nexus\Http\Routing\Route;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    #[Test]
    public function it_runs_its_closure_with_the_given_context(): void
    {
        $captured = null;
        $route = new Route(static function (RequestCtx $ctx) use (&$captured): ResponseInterface {
            $captured = $ctx;
            return new Response(200);
        });

        $ctx = $this->createStub(RequestCtx::class);
        $response = ($route->run)($ctx);

        self::assertSame($ctx, $captured);
        self::assertSame(200, $response?->getStatusCode());
    }

    #[Test]
    public function it_can_return_null_to_signal_rejection(): void
    {
        $route = new Route(static fn(): ?ResponseInterface => null);
        $ctx = $this->createStub(RequestCtx::class);

        self::assertNull(($route->run)($ctx));
    }
}
```

- [ ] **Step 2: Run test (expect fail)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Routing/RouteTest.php`
Expected: FAIL — `Monadial\Nexus\Http\Routing\Route` not found.

- [ ] **Step 3: Implement `packages/nexus-http/src/Routing/Route.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use Closure;
use Monadial\Nexus\Http\RequestCtx;
use Psr\Http\Message\ResponseInterface;

/**
 * Composable HTTP route: a function from RequestCtx to ?ResponseInterface.
 *
 * Returning null signals rejection (try a sibling in concat / fall through to 404).
 * Returning a ResponseInterface is a completion.
 */
final readonly class Route
{
    /** @param Closure(RequestCtx): ?ResponseInterface $run */
    public function __construct(public Closure $run) {}
}
```

- [ ] **Step 4: Stub `RequestCtx` interface so `Route` references resolve**

Create `packages/nexus-http/src/RequestCtx.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http;

interface RequestCtx
{
}
```

(We'll grow this interface in Task 6.)

- [ ] **Step 5: Run test (expect pass)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Routing/RouteTest.php`
Expected: PASS, 2 assertions.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http/src/Routing/Route.php packages/nexus-http/src/RequestCtx.php packages/nexus-http/tests/Unit/Routing/RouteTest.php
git commit -m "feat(http): introduce Route value object and RequestCtx interface stub"
```

---

## Task 5: Rejection types

**Files:**
- Create: `packages/nexus-http/src/Rejection/RouteRejection.php`
- Create: `packages/nexus-http/src/Rejection/RouteNotFoundRejection.php`
- Create: `packages/nexus-http/src/Rejection/MethodNotAllowedRejection.php`
- Create: `packages/nexus-http/src/Rejection/ExtractorRejection.php`
- Create: `packages/nexus-http/src/Rejection/BodyParseException.php`
- Create: `packages/nexus-http/tests/Unit/Rejection/RouteRejectionTest.php`

- [ ] **Step 1: Write failing test `RouteRejectionTest.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Rejection;

use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Monadial\Nexus\Http\Rejection\MethodNotAllowedRejection;
use Monadial\Nexus\Http\Rejection\RouteNotFoundRejection;
use Monadial\Nexus\Http\Rejection\RouteRejection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteRejection::class)]
#[CoversClass(RouteNotFoundRejection::class)]
#[CoversClass(MethodNotAllowedRejection::class)]
#[CoversClass(ExtractorRejection::class)]
final class RouteRejectionTest extends TestCase
{
    #[Test]
    public function route_rejection_carries_status_code_and_message(): void
    {
        $rejection = new RouteRejection('bad_request', 'broken', 400);

        self::assertSame('bad_request', $rejection->code);
        self::assertSame('broken', $rejection->message);
        self::assertSame(400, $rejection->status);
    }

    #[Test]
    public function not_found_defaults_to_404(): void
    {
        $rejection = new RouteNotFoundRejection('/missing');

        self::assertSame(404, $rejection->status);
        self::assertSame('not_found', $rejection->code);
        self::assertStringContainsString('/missing', $rejection->message);
    }

    #[Test]
    public function method_not_allowed_carries_allowed_methods(): void
    {
        $rejection = new MethodNotAllowedRejection('PATCH', ['GET', 'POST']);

        self::assertSame(405, $rejection->status);
        self::assertSame(['GET', 'POST'], $rejection->allowed);
    }

    #[Test]
    public function extractor_rejection_400(): void
    {
        $rejection = new ExtractorRejection('orders/abc', 'expected integer');

        self::assertSame(400, $rejection->status);
        self::assertSame('extractor_failed', $rejection->code);
        self::assertStringContainsString('orders/abc', $rejection->message);
    }
}
```

- [ ] **Step 2: Run test (expect fail)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Rejection/RouteRejectionTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement rejection classes**

`packages/nexus-http/src/Rejection/RouteRejection.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

use RuntimeException;

class RouteRejection extends RuntimeException
{
    public function __construct(
        public readonly string $code,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }

    public string $message {
        get => parent::getMessage();
    }
}
```

`packages/nexus-http/src/Rejection/RouteNotFoundRejection.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

final class RouteNotFoundRejection extends RouteRejection
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('not_found', "no route matched '{$path}'", 404);
    }
}
```

`packages/nexus-http/src/Rejection/MethodNotAllowedRejection.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

final class MethodNotAllowedRejection extends RouteRejection
{
    /** @param list<string> $allowed */
    public function __construct(
        public readonly string $method,
        public readonly array $allowed,
    ) {
        parent::__construct(
            'method_not_allowed',
            "method '{$method}' not allowed; allowed: " . implode(', ', $allowed),
            405,
        );
    }
}
```

`packages/nexus-http/src/Rejection/ExtractorRejection.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

final class ExtractorRejection extends RouteRejection
{
    public function __construct(string $where, string $reason)
    {
        parent::__construct('extractor_failed', "{$where}: {$reason}", 400);
    }
}
```

`packages/nexus-http/src/Rejection/BodyParseException.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

final class BodyParseException extends RouteRejection
{
    public function __construct(string $reason)
    {
        parent::__construct('body_parse_failed', $reason, 400);
    }
}
```

- [ ] **Step 4: Run tests (expect pass)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Rejection/RouteRejectionTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Rejection packages/nexus-http/tests/Unit/Rejection
git commit -m "feat(http): introduce RouteRejection hierarchy"
```

---

## Task 6: `RequestCtx` interface + `DefaultRequestCtx`

**Files:**
- Modify: `packages/nexus-http/src/RequestCtx.php` (expand from Task 4 stub)
- Create: `packages/nexus-http/src/DefaultRequestCtx.php`
- Create: `packages/nexus-http/tests/Unit/DefaultRequestCtxTest.php`

- [ ] **Step 1: Write failing test**

`packages/nexus-http/tests/Unit/DefaultRequestCtxTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DefaultRequestCtx::class)]
final class DefaultRequestCtxTest extends TestCase
{
    #[Test]
    public function it_returns_path_param_from_attribute(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/orders/42');

        $ctx = new DefaultRequestCtx(
            request: $request,
            params: ['id' => '42'],
            system: $this->createStub(ActorSystem::class),
            registry: new MarshallerRegistry(),
            logger: new NullLogger(),
        );

        self::assertSame('42', $ctx->param('id'));
        self::assertNull($ctx->param('missing'));
    }

    #[Test]
    public function with_param_returns_a_new_ctx_with_added_param(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');

        $ctx = new DefaultRequestCtx(
            request: $request,
            params: [],
            system: $this->createStub(ActorSystem::class),
            registry: new MarshallerRegistry(),
            logger: new NullLogger(),
        );
        $next = $ctx->withParam('id', '7');

        self::assertNull($ctx->param('id'));
        self::assertSame('7', $next->param('id'));
    }
}
```

- [ ] **Step 2: Run test (expect fail)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/DefaultRequestCtxTest.php`
Expected: FAIL — classes missing.

- [ ] **Step 3: Replace `packages/nexus-http/src/RequestCtx.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Marshalling\Marshaller;
use Monadial\Nexus\Http\Marshalling\MediaType;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

interface RequestCtx
{
    public function request(): ServerRequestInterface;

    public function param(string $name): ?string;

    public function withParam(string $name, string $value): self;

    public function system(): ActorSystem;

    /** @return ActorRef<object>|null */
    public function actorFor(string $path): ?ActorRef;

    public function ask(string $path, object $message, ?Duration $timeout = null): mixed;

    public function marshallerFor(MediaType $type): Marshaller;

    public function negotiate(): MediaType;

    public function log(): LoggerInterface;
}
```

- [ ] **Step 4: Implement `packages/nexus-http/src/DefaultRequestCtx.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Marshalling\Marshaller;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Marshalling\MediaType;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final readonly class DefaultRequestCtx implements RequestCtx
{
    /** @param array<string, string> $params */
    public function __construct(
        public ServerRequestInterface $request,
        public array $params,
        public ActorSystem $system,
        public MarshallerRegistry $registry,
        public LoggerInterface $logger,
    ) {}

    #[Override]
    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    #[Override]
    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    #[Override]
    public function withParam(string $name, string $value): self
    {
        return new self(
            $this->request,
            [...$this->params, $name => $value],
            $this->system,
            $this->registry,
            $this->logger,
        );
    }

    #[Override]
    public function system(): ActorSystem
    {
        return $this->system;
    }

    /** @return ActorRef<object>|null */
    #[Override]
    public function actorFor(string $path): ?ActorRef
    {
        /** @var ActorRef<object>|null $ref */
        $ref = $this->system->actorFor($path);
        return $ref;
    }

    #[Override]
    public function ask(string $path, object $message, ?Duration $timeout = null): mixed
    {
        $ref = $this->actorFor($path);
        if ($ref === null) {
            throw new \RuntimeException("no actor at path '{$path}'");
        }
        return $ref->ask($message, $timeout ?? Duration::seconds(5))->await();
    }

    #[Override]
    public function marshallerFor(MediaType $type): Marshaller
    {
        return $this->registry->byMediaType($type);
    }

    #[Override]
    public function negotiate(): MediaType
    {
        $accept = $this->request->getHeaderLine('Accept');
        return $this->registry->negotiate($accept)->mediaType();
    }

    #[Override]
    public function log(): LoggerInterface
    {
        return $this->logger;
    }
}
```

- [ ] **Step 5: Run test**

Note: this test depends on `MarshallerRegistry` which arrives in Task 10. Skip the test for now; we'll re-run after Task 10.

For now, run: `docker compose exec php vendor/bin/psalm`
Expected: errors due to missing `Marshaller` / `MarshallerRegistry` — that's OK; the next tasks add them.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http/src/RequestCtx.php packages/nexus-http/src/DefaultRequestCtx.php packages/nexus-http/tests/Unit/DefaultRequestCtxTest.php
git commit -m "feat(http): RequestCtx interface and DefaultRequestCtx implementation"
```

---

## Task 7: `MediaType` value object

**Files:**
- Create: `packages/nexus-http/src/Marshalling/MediaType.php`
- Create: `packages/nexus-http/tests/Unit/Marshalling/MediaTypeTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Marshalling;

use Monadial\Nexus\Http\Marshalling\MediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaType::class)]
final class MediaTypeTest extends TestCase
{
    #[Test]
    public function parses_application_json(): void
    {
        $mt = MediaType::parse('application/json');
        self::assertSame('application', $mt->type);
        self::assertSame('json', $mt->subtype);
        self::assertSame('application/json', (string) $mt);
    }

    #[Test]
    public function parses_with_parameters(): void
    {
        $mt = MediaType::parse('text/html; charset=utf-8');
        self::assertSame('text', $mt->type);
        self::assertSame('html', $mt->subtype);
        self::assertSame(['charset' => 'utf-8'], $mt->params);
    }

    #[Test]
    public function equality_ignores_params(): void
    {
        self::assertTrue(
            MediaType::parse('application/json')->matches(
                MediaType::parse('application/json; charset=utf-8'),
            ),
        );
    }

    #[Test]
    public function wildcard_subtype_matches_anything_in_type(): void
    {
        self::assertTrue(MediaType::parse('application/*')->matches(MediaType::parse('application/json')));
        self::assertFalse(MediaType::parse('application/*')->matches(MediaType::parse('text/html')));
    }
}
```

- [ ] **Step 2: Run test (expect fail)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Marshalling/MediaTypeTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement `packages/nexus-http/src/Marshalling/MediaType.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Marshalling;

use Stringable;

final readonly class MediaType implements Stringable
{
    /** @param array<string, string> $params */
    public function __construct(
        public string $type,
        public string $subtype,
        public array $params = [],
    ) {}

    public static function parse(string $value): self
    {
        $value = trim($value);
        $parts = array_map('trim', explode(';', $value));
        $primary = array_shift($parts) ?? '';
        [$type, $subtype] = array_pad(explode('/', $primary, 2), 2, '*');

        $params = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $params[trim($k)] = trim($v);
        }

        return new self(strtolower($type), strtolower($subtype), $params);
    }

    public function matches(self $other): bool
    {
        if ($this->type !== '*' && $this->type !== $other->type) {
            return false;
        }

        return $this->subtype === '*' || $this->subtype === $other->subtype;
    }

    public function __toString(): string
    {
        return "{$this->type}/{$this->subtype}";
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Marshalling/MediaTypeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Marshalling/MediaType.php packages/nexus-http/tests/Unit/Marshalling/MediaTypeTest.php
git commit -m "feat(http): MediaType value object with parse and matches"
```

---

## Task 8: `Marshaller` interface + `JsonValinorMarshaller`

**Files:**
- Create: `packages/nexus-http/src/Marshalling/Marshaller.php`
- Create: `packages/nexus-http/src/Marshalling/JsonValinorMarshaller.php`
- Create: `packages/nexus-http/tests/Unit/Marshalling/JsonValinorMarshallerTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Marshalling;

use CuyZ\Valinor\MapperBuilder;
use Monadial\Nexus\Http\Marshalling\JsonValinorMarshaller;
use Monadial\Nexus\Http\Marshalling\MediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonValinorMarshaller::class)]
final class JsonValinorMarshallerTest extends TestCase
{
    #[Test]
    public function media_type_is_application_json(): void
    {
        $m = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        self::assertSame('application/json', (string) $m->mediaType());
    }

    #[Test]
    public function marshals_arrays_as_json(): void
    {
        $m = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        self::assertSame('{"a":1}', $m->marshal(['a' => 1]));
    }

    #[Test]
    public function marshals_objects_via_get_object_vars(): void
    {
        $m = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        $obj = new readonly class (1, 'x') {
            public function __construct(public int $id, public string $name) {}
        };
        self::assertSame('{"id":1,"name":"x"}', $m->marshal($obj));
    }

    #[Test]
    public function unmarshals_json_into_typed_value(): void
    {
        $m = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        $cmd = $m->unmarshal('{"sku":"X","qty":3}', UnmarshalSample::class);

        self::assertInstanceOf(UnmarshalSample::class, $cmd);
        self::assertSame('X', $cmd->sku);
        self::assertSame(3, $cmd->qty);
    }
}

final readonly class UnmarshalSample
{
    public function __construct(public string $sku, public int $qty) {}
}
```

- [ ] **Step 2: Implement `packages/nexus-http/src/Marshalling/Marshaller.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Marshalling;

interface Marshaller
{
    public function mediaType(): MediaType;

    /**
     * @template T
     * @param class-string<T> $targetType
     * @return T
     */
    public function unmarshal(string $body, string $targetType): mixed;

    public function marshal(mixed $value): string;
}
```

- [ ] **Step 3: Implement `packages/nexus-http/src/Marshalling/JsonValinorMarshaller.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Marshalling;

use CuyZ\Valinor\Mapper\TreeMapper;
use Monadial\Nexus\Http\Rejection\BodyParseException;
use Override;

final readonly class JsonValinorMarshaller implements Marshaller
{
    public function __construct(private TreeMapper $mapper) {}

    #[Override]
    public function mediaType(): MediaType
    {
        return new MediaType('application', 'json');
    }

    /**
     * @template T
     * @param class-string<T> $targetType
     * @return T
     */
    #[Override]
    public function unmarshal(string $body, string $targetType): mixed
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BodyParseException('invalid JSON: ' . $e->getMessage());
        }

        try {
            /** @var T $value */
            $value = $this->mapper->map($targetType, $decoded);
            return $value;
        } catch (\Throwable $e) {
            throw new BodyParseException('JSON does not match ' . $targetType . ': ' . $e->getMessage());
        }
    }

    #[Override]
    public function marshal(mixed $value): string
    {
        if (is_object($value) && !$value instanceof \JsonSerializable) {
            $value = get_object_vars($value);
        }

        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        return $encoded;
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Marshalling/JsonValinorMarshallerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Marshalling/Marshaller.php packages/nexus-http/src/Marshalling/JsonValinorMarshaller.php packages/nexus-http/tests/Unit/Marshalling/JsonValinorMarshallerTest.php
git commit -m "feat(http): Marshaller interface and JsonValinorMarshaller"
```

---

## Task 9: `MarshallerRegistry` + content negotiation

**Files:**
- Create: `packages/nexus-http/src/Marshalling/MarshallerRegistry.php`
- Create: `packages/nexus-http/tests/Unit/Marshalling/MarshallerRegistryTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Marshalling;

use CuyZ\Valinor\MapperBuilder;
use Monadial\Nexus\Http\Marshalling\JsonValinorMarshaller;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Marshalling\MediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarshallerRegistry::class)]
final class MarshallerRegistryTest extends TestCase
{
    private function jsonMarshaller(): JsonValinorMarshaller
    {
        return new JsonValinorMarshaller((new MapperBuilder())->mapper());
    }

    #[Test]
    public function default_registers_json(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        self::assertSame('application/json', (string) $registry->default()->mediaType());
    }

    #[Test]
    public function negotiates_json_when_accept_is_star(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        $m = $registry->negotiate('*/*');
        self::assertSame('application/json', (string) $m->mediaType());
    }

    #[Test]
    public function negotiates_highest_q_match(): void
    {
        $registry = (new MarshallerRegistry())
            ->register($this->jsonMarshaller());
        $m = $registry->negotiate('text/html;q=0.5, application/json;q=0.9');
        self::assertSame('application/json', (string) $m->mediaType());
    }

    #[Test]
    public function returns_default_when_no_match(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        $m = $registry->negotiate('text/csv');
        self::assertSame('application/json', (string) $m->mediaType());
    }

    #[Test]
    public function caches_negotiation_results(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        $first = $registry->negotiate('application/json;q=1.0');
        $second = $registry->negotiate('application/json;q=1.0');
        self::assertSame($first, $second);
    }

    #[Test]
    public function lookup_by_media_type(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        $m = $registry->byMediaType(MediaType::parse('application/json'));
        self::assertSame('application/json', (string) $m->mediaType());
    }
}
```

- [ ] **Step 2: Implement `packages/nexus-http/src/Marshalling/MarshallerRegistry.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Marshalling;

use CuyZ\Valinor\MapperBuilder;
use RuntimeException;

final class MarshallerRegistry
{
    private const int CACHE_LIMIT = 64;

    /** @var array<string, Marshaller> */
    private array $byMediaType = [];

    private ?Marshaller $default = null;

    /** @var array<string, Marshaller> */
    private array $cache = [];

    public static function withDefaults(): self
    {
        return (new self())->register(
            new JsonValinorMarshaller((new MapperBuilder())->mapper()),
        );
    }

    public function register(Marshaller $marshaller): self
    {
        $key = (string) $marshaller->mediaType();
        $this->byMediaType[$key] = $marshaller;
        $this->default ??= $marshaller;
        $this->cache = [];
        return $this;
    }

    public function default(): Marshaller
    {
        if ($this->default === null) {
            throw new RuntimeException('no marshaller registered');
        }
        return $this->default;
    }

    public function byMediaType(MediaType $type): Marshaller
    {
        $key = (string) $type;
        if (isset($this->byMediaType[$key])) {
            return $this->byMediaType[$key];
        }

        foreach ($this->byMediaType as $registered) {
            if ($registered->mediaType()->matches($type)) {
                return $registered;
            }
        }

        throw new RuntimeException("no marshaller for {$key}");
    }

    public function negotiate(string $acceptHeader): Marshaller
    {
        if (isset($this->cache[$acceptHeader])) {
            return $this->cache[$acceptHeader];
        }

        $best = $this->default();
        $bestQ = -1.0;

        foreach ($this->parseAccept($acceptHeader) as [$mt, $q]) {
            foreach ($this->byMediaType as $candidate) {
                if (!$mt->matches($candidate->mediaType())) {
                    continue;
                }
                if ($q > $bestQ) {
                    $best = $candidate;
                    $bestQ = $q;
                }
            }
        }

        if (count($this->cache) >= self::CACHE_LIMIT) {
            array_shift($this->cache);
        }
        $this->cache[$acceptHeader] = $best;

        return $best;
    }

    /** @return iterable<array{0: MediaType, 1: float}> */
    private function parseAccept(string $header): iterable
    {
        if ($header === '') {
            yield [MediaType::parse('*/*'), 1.0];
            return;
        }

        foreach (explode(',', $header) as $entry) {
            $mt = MediaType::parse($entry);
            $q = isset($mt->params['q']) ? (float) $mt->params['q'] : 1.0;
            yield [$mt, $q];
        }
    }
}
```

- [ ] **Step 3: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Marshalling/MarshallerRegistryTest.php`
Expected: PASS, 6 tests.

Also re-run Task 6's test now that `MarshallerRegistry` exists:
Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/DefaultRequestCtxTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http/src/Marshalling/MarshallerRegistry.php packages/nexus-http/tests/Unit/Marshalling/MarshallerRegistryTest.php
git commit -m "feat(http): MarshallerRegistry with Accept negotiation and cache"
```

---

## Task 10: Bootstrap directive function file

**Files:**
- Create: `packages/nexus-http/src/Directive/functions.php`

The empty stub gives the autoload loader something to find. Subsequent tasks fill it.

- [ ] **Step 1: Create `packages/nexus-http/src/Directive/functions.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http;

// Directive functions are appended to this file by the tasks that follow.
// All directives live in this single namespace and are autoloaded as composer "files".
```

- [ ] **Step 2: Verify autoload**

Run: `docker compose exec php composer dump-autoload`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add packages/nexus-http/src/Directive/functions.php
git commit -m "feat(http): bootstrap directive functions file"
```

---

## Task 11: Terminal directives — `complete`, `completeWith`, `completeBuilt`, `redirect`, `reject`

**Files:**
- Modify: `packages/nexus-http/src/Directive/functions.php`
- Create: `packages/nexus-http/tests/Unit/Directive/CompleteTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use CuyZ\Valinor\MapperBuilder;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Marshalling\JsonValinorMarshaller;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Rejection\RouteRejection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\completeBuilt;
use function Monadial\Nexus\Http\completeWith;
use function Monadial\Nexus\Http\redirect;
use function Monadial\Nexus\Http\reject;

final class CompleteTest extends TestCase
{
    private function ctx(string $accept = 'application/json'): DefaultRequestCtx
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Accept', $accept);

        return new DefaultRequestCtx(
            request: $request,
            params: [],
            system: $this->createStub(ActorSystem::class),
            registry: MarshallerRegistry::withDefaults(),
            logger: new NullLogger(),
        );
    }

    #[Test]
    public function complete_value_marshals_to_json_body(): void
    {
        $route = complete(['hello' => 'world']);
        $response = ($route->run)($this->ctx());

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"hello":"world"}', (string) $response->getBody());
    }

    #[Test]
    public function complete_with_status(): void
    {
        $route = complete(['ok' => true], 201);
        $response = ($route->run)($this->ctx());
        self::assertSame(201, $response?->getStatusCode());
    }

    #[Test]
    public function complete_callable_value_is_invoked_with_ctx(): void
    {
        $route = complete(static fn($ctx) => ['method' => $ctx->request()->getMethod()]);
        $response = ($route->run)($this->ctx());
        self::assertSame('{"method":"GET"}', (string) $response?->getBody());
    }

    #[Test]
    public function complete_with_returns_explicit_response(): void
    {
        $route = completeWith(new Response(204));
        self::assertSame(204, ($route->run)($this->ctx())?->getStatusCode());
    }

    #[Test]
    public function complete_built_invokes_builder(): void
    {
        $route = completeBuilt(static fn($ctx) => new Response(202));
        self::assertSame(202, ($route->run)($this->ctx())?->getStatusCode());
    }

    #[Test]
    public function redirect_sets_location_and_default_302(): void
    {
        $route = redirect('/elsewhere');
        $response = ($route->run)($this->ctx());
        self::assertSame(302, $response?->getStatusCode());
        self::assertSame('/elsewhere', $response?->getHeaderLine('Location'));
    }

    #[Test]
    public function reject_throws(): void
    {
        $this->expectException(RouteRejection::class);
        $route = reject(new RouteRejection('forbidden', 'no', 403));
        ($route->run)($this->ctx());
    }
}
```

- [ ] **Step 2: Append to `packages/nexus-http/src/Directive/functions.php`**

```php
use Closure;
use Monadial\Nexus\Http\Rejection\RouteRejection;
use Monadial\Nexus\Http\Routing\Route;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Complete a request with a value (auto-marshalled by the negotiated marshaller).
 *
 * If $value is callable, it is invoked with RequestCtx and its return is marshalled.
 */
function complete(mixed $value, int $status = 200): Route
{
    return new Route(static function (RequestCtx $ctx) use ($value, $status): ResponseInterface {
        $resolved = is_callable($value) ? $value($ctx) : $value;
        $marshaller = $ctx->marshallerFor($ctx->negotiate());
        $body = $marshaller->marshal($resolved);

        return (new Response($status))
            ->withHeader('Content-Type', (string) $marshaller->mediaType())
            ->withBody(\Nyholm\Psr7\Stream::create($body));
    });
}

/** Complete with an explicit PSR-7 Response. */
function completeWith(ResponseInterface $response): Route
{
    return new Route(static fn(RequestCtx $ctx): ResponseInterface => $response);
}

/** Complete with a builder closure that constructs the Response. */
function completeBuilt(Closure $build): Route
{
    return new Route(static fn(RequestCtx $ctx): ResponseInterface => $build($ctx));
}

/** Issue a redirect (defaults to 302 Found). */
function redirect(string $location, int $status = 302): Route
{
    return new Route(static fn(RequestCtx $ctx): ResponseInterface =>
        (new Response($status))->withHeader('Location', $location),
    );
}

/** Throw a rejection — caught by the surrounding error mapper. */
function reject(RouteRejection $rejection): Route
{
    return new Route(static function (RequestCtx $ctx) use ($rejection): never {
        throw $rejection;
    });
}
```

- [ ] **Step 3: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Directive/CompleteTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http/src/Directive/functions.php packages/nexus-http/tests/Unit/Directive/CompleteTest.php
git commit -m "feat(http): terminal directives (complete, completeWith, completeBuilt, redirect, reject)"
```

---

## Task 12: Method directives — `get`, `post`, `put`, `delete`, `patch`, `method`

**Files:**
- Modify: `packages/nexus-http/src/Directive/functions.php`
- Create: `packages/nexus-http/tests/Unit/Directive/MethodTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\delete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\method as httpMethod;
use function Monadial\Nexus\Http\patch;
use function Monadial\Nexus\Http\post;
use function Monadial\Nexus\Http\put;

final class MethodTest extends TestCase
{
    #[Test]
    public function get_passes_only_for_GET(): void
    {
        $route = get(static fn() => complete(['ok' => true]));

        self::assertNotNull(($route->run)(CtxFactory::with('GET', '/')));
        self::assertNull(($route->run)(CtxFactory::with('POST', '/')));
    }

    #[Test]
    public function post_passes_only_for_POST(): void
    {
        $route = post(static fn() => complete(null));
        self::assertNotNull(($route->run)(CtxFactory::with('POST', '/')));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/')));
    }

    #[Test]
    public function put_delete_patch_each_match_their_verb(): void
    {
        self::assertNotNull((put(static fn() => complete(null))->run)(CtxFactory::with('PUT', '/')));
        self::assertNotNull((delete(static fn() => complete(null))->run)(CtxFactory::with('DELETE', '/')));
        self::assertNotNull((patch(static fn() => complete(null))->run)(CtxFactory::with('PATCH', '/')));
    }

    #[Test]
    public function method_is_a_generic_verb_directive(): void
    {
        $route = httpMethod('PROPFIND', static fn() => complete(null));
        self::assertNotNull(($route->run)(CtxFactory::with('PROPFIND', '/')));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/')));
    }
}
```

- [ ] **Step 2: Create test helper `packages/nexus-http/tests/Unit/Directive/Helpers/CtxFactory.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive\Helpers;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;

final class CtxFactory
{
    public static function with(string $method, string $uri, ?string $body = null): DefaultRequestCtx
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withBody($factory->createStream($body));
        }

        return new DefaultRequestCtx(
            request: $request,
            params: [],
            system: ActorSystem::create('test-ctx', new StepRuntime()),
            registry: MarshallerRegistry::withDefaults(),
            logger: new NullLogger(),
        );
    }
}
```

(Using PHPUnit's `Generator` so the helper isn't tied to a `TestCase`.)

- [ ] **Step 3: Append to `functions.php`**

```php
function method(string $verb, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($verb, $child): ?ResponseInterface {
        if ($ctx->request()->getMethod() !== $verb) {
            return null;
        }
        $next = $child();
        return ($next->run)($ctx);
    });
}

function get(Closure $child): Route { return method('GET', $child); }
function post(Closure $child): Route { return method('POST', $child); }
function put(Closure $child): Route { return method('PUT', $child); }
function delete(Closure $child): Route { return method('DELETE', $child); }
function patch(Closure $child): Route { return method('PATCH', $child); }
```

Note: `$child` is a `Closure` returning `Route`. The directive evaluates the closure to get the next `Route`, then runs it.

- [ ] **Step 4: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Directive/MethodTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Directive/functions.php packages/nexus-http/tests/Unit/Directive/MethodTest.php packages/nexus-http/tests/Unit/Directive/Helpers
git commit -m "feat(http): method directives (get, post, put, delete, patch, method)"
```

---

## Task 13: `concat` directive

**Files:**
- Modify: `packages/nexus-http/src/Directive/functions.php`
- Create: `packages/nexus-http/tests/Unit/Directive/ConcatTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function Monadial\Nexus\Http\concat;

final class ConcatTest extends TestCase
{
    #[Test]
    public function returns_first_non_null_route(): void
    {
        $reject = new Route(static fn() => null);
        $accept = new Route(static fn(): ResponseInterface => new Response(200));

        $route = concat($reject, $accept);

        self::assertSame(200, ($route->run)(CtxFactory::with('GET', '/'))?->getStatusCode());
    }

    #[Test]
    public function returns_null_if_all_reject(): void
    {
        $route = concat(
            new Route(static fn() => null),
            new Route(static fn() => null),
        );

        self::assertNull(($route->run)(CtxFactory::with('GET', '/')));
    }

    #[Test]
    public function returns_null_if_no_children(): void
    {
        self::assertNull((concat()->run)(CtxFactory::with('GET', '/')));
    }
}
```

- [ ] **Step 2: Append to `functions.php`**

```php
function concat(Route ...$routes): Route
{
    return new Route(static function (RequestCtx $ctx) use ($routes): ?ResponseInterface {
        foreach ($routes as $route) {
            $response = ($route->run)($ctx);
            if ($response !== null) {
                return $response;
            }
        }
        return null;
    });
}
```

- [ ] **Step 3: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Directive/ConcatTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http/src/Directive/functions.php packages/nexus-http/tests/Unit/Directive/ConcatTest.php
git commit -m "feat(http): concat directive"
```

---

## Task 14: Path directives — `pathPrefix`, `pathEnd` + path consumption helper

**Files:**
- Create: `packages/nexus-http/src/Routing/PathState.php`
- Modify: `packages/nexus-http/src/RequestCtx.php`
- Modify: `packages/nexus-http/src/DefaultRequestCtx.php`
- Modify: `packages/nexus-http/src/Directive/functions.php`
- Create: `packages/nexus-http/tests/Unit/Directive/PathPrefixTest.php`

- [ ] **Step 1: Add `PathState` value object**

`packages/nexus-http/src/Routing/PathState.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

final readonly class PathState
{
    /** @param list<string> $remaining */
    public function __construct(public array $remaining) {}

    public static function fromPath(string $path): self
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return new self([]);
        }
        return new self(explode('/', $trimmed));
    }

    public function consume(string $segment): ?self
    {
        if ($this->remaining === [] || $this->remaining[0] !== $segment) {
            return null;
        }
        return new self(array_slice($this->remaining, 1));
    }

    public function consumeAny(): ?array
    {
        if ($this->remaining === []) {
            return null;
        }
        return [
            $this->remaining[0],
            new self(array_slice($this->remaining, 1)),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->remaining === [];
    }
}
```

- [ ] **Step 2: Add `pathState()` accessor to `RequestCtx`**

Append to `RequestCtx`:
```php
public function pathState(): PathState;
public function withPathState(PathState $state): self;
```

(Add the matching `use` for `Routing\PathState`.)

- [ ] **Step 3: Update `DefaultRequestCtx`**

Add a `?PathState` field defaulting to lazy-init from `request->getUri()->getPath()`:

```php
private ?PathState $pathState = null;

public function pathState(): PathState
{
    return $this->pathState ??= PathState::fromPath($this->request->getUri()->getPath());
}

public function withPathState(PathState $state): self
{
    $next = clone $this;
    $next->pathState = $state;
    return $next;
}
```

(Make `$pathState` non-readonly: drop the class-level `readonly` keyword, mark each constructor-promoted property `public readonly` individually as needed. Keep the class `final`.)

- [ ] **Step 4: Write failing test**

`packages/nexus-http/tests/Unit/Directive/PathPrefixTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\pathEnd;
use function Monadial\Nexus\Http\pathPrefix;

final class PathPrefixTest extends TestCase
{
    #[Test]
    public function path_prefix_consumes_one_segment(): void
    {
        $route = pathPrefix('orders', static fn() => get(static fn() => complete(['list'])));

        self::assertNotNull(($route->run)(CtxFactory::with('GET', '/orders/42')));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/payments')));
    }

    #[Test]
    public function path_end_only_completes_when_path_is_fully_consumed(): void
    {
        $route = pathPrefix('orders', static fn() =>
            pathEnd(static fn() => get(static fn() => complete(['list']))),
        );

        self::assertNotNull(($route->run)(CtxFactory::with('GET', '/orders')));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/orders/42')));
    }
}
```

- [ ] **Step 5: Append directives to `functions.php`**

```php
use Monadial\Nexus\Http\Routing\PathState;

function pathPrefix(string $literal, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($literal, $child): ?ResponseInterface {
        $next = $ctx->pathState()->consume($literal);
        if ($next === null) {
            return null;
        }
        $route = $child();
        return ($route->run)($ctx->withPathState($next));
    });
}

function pathEnd(Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($child): ?ResponseInterface {
        if (!$ctx->pathState()->isEmpty()) {
            return null;
        }
        $route = $child();
        return ($route->run)($ctx);
    });
}
```

- [ ] **Step 6: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Directive/PathPrefixTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-http/src/Routing/PathState.php packages/nexus-http/src/RequestCtx.php packages/nexus-http/src/DefaultRequestCtx.php packages/nexus-http/src/Directive/functions.php packages/nexus-http/tests/Unit/Directive/PathPrefixTest.php
git commit -m "feat(http): pathPrefix and pathEnd directives via PathState"
```

---

## Task 15: Extractor interface + scalar extractors

**Files:**
- Create: `packages/nexus-http/src/Extract/Extractor.php`
- Create: `packages/nexus-http/src/Extract/IntNumber.php`
- Create: `packages/nexus-http/src/Extract/LongNumber.php`
- Create: `packages/nexus-http/src/Extract/StringSegment.php`
- Create: `packages/nexus-http/src/Extract/UlidSegment.php`
- Create: `packages/nexus-http/src/Extract/UuidSegment.php`
- Create: `packages/nexus-http/src/Extract/Remaining.php`
- Create: `packages/nexus-http/tests/Unit/Extract/ExtractorsTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Extract;

use Monadial\Nexus\Http\Extract\IntNumber;
use Monadial\Nexus\Http\Extract\LongNumber;
use Monadial\Nexus\Http\Extract\StringSegment;
use Monadial\Nexus\Http\Extract\UlidSegment;
use Monadial\Nexus\Http\Extract\UuidSegment;
use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

final class ExtractorsTest extends TestCase
{
    #[Test]
    public function int_number_parses_positive_integers(): void
    {
        self::assertSame(42, (new IntNumber())->fromSegment('42'));
    }

    #[Test]
    public function int_number_rejects_non_integer(): void
    {
        $this->expectException(ExtractorRejection::class);
        (new IntNumber())->fromSegment('abc');
    }

    #[Test]
    public function long_number_parses_large_integers(): void
    {
        self::assertSame(9_000_000_000, (new LongNumber())->fromSegment('9000000000'));
    }

    #[Test]
    public function string_segment_returns_value(): void
    {
        self::assertSame('hello', (new StringSegment())->fromSegment('hello'));
    }

    #[Test]
    public function ulid_segment_returns_ulid(): void
    {
        $ulid = (new UlidSegment())->fromSegment('01HW00000000000000000000ZZ');
        self::assertInstanceOf(Ulid::class, $ulid);
    }

    #[Test]
    public function uuid_segment_returns_uuid(): void
    {
        $uuid = (new UuidSegment())->fromSegment('550e8400-e29b-41d4-a716-446655440000');
        self::assertInstanceOf(Uuid::class, $uuid);
    }

    #[Test]
    public function ulid_segment_rejects_invalid_value(): void
    {
        $this->expectException(ExtractorRejection::class);
        (new UlidSegment())->fromSegment('not-a-ulid');
    }
}
```

- [ ] **Step 2: Implement `Extractor` interface**

`packages/nexus-http/src/Extract/Extractor.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

/**
 * @template T
 */
interface Extractor
{
    /**
     * Extracts a typed value from a single path segment.
     *
     * @return T
     */
    public function fromSegment(string $segment): mixed;
}
```

- [ ] **Step 3: Implement scalar extractors**

`packages/nexus-http/src/Extract/IntNumber.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Override;

/** @implements Extractor<int> */
final readonly class IntNumber implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): int
    {
        if (!ctype_digit($segment) && !preg_match('/^-?\d+$/', $segment)) {
            throw new ExtractorRejection("path segment '{$segment}'", 'expected integer');
        }
        return (int) $segment;
    }
}
```

`packages/nexus-http/src/Extract/LongNumber.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Override;

/** @implements Extractor<int> */
final readonly class LongNumber implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): int
    {
        if (!preg_match('/^-?\d+$/', $segment)) {
            throw new ExtractorRejection("path segment '{$segment}'", 'expected long integer');
        }
        return (int) $segment;
    }
}
```

`packages/nexus-http/src/Extract/StringSegment.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use Override;

/** @implements Extractor<string> */
final readonly class StringSegment implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): string
    {
        return $segment;
    }
}
```

`packages/nexus-http/src/Extract/UlidSegment.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use InvalidArgumentException;
use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Override;
use Symfony\Component\Uid\Ulid;

/** @implements Extractor<Ulid> */
final readonly class UlidSegment implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): Ulid
    {
        try {
            return Ulid::fromString($segment);
        } catch (InvalidArgumentException) {
            throw new ExtractorRejection("path segment '{$segment}'", 'expected ULID');
        }
    }
}
```

`packages/nexus-http/src/Extract/UuidSegment.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use InvalidArgumentException;
use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Override;
use Symfony\Component\Uid\Uuid;

/** @implements Extractor<Uuid> */
final readonly class UuidSegment implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): Uuid
    {
        try {
            return Uuid::fromString($segment);
        } catch (InvalidArgumentException) {
            throw new ExtractorRejection("path segment '{$segment}'", 'expected UUID');
        }
    }
}
```

`packages/nexus-http/src/Extract/Remaining.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use Override;

/**
 * Greedy extractor: consumes the rest of the path and returns it as a slash-joined string.
 *
 * @implements Extractor<string>
 */
final readonly class Remaining implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): string
    {
        return $segment;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Extract/ExtractorsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Extract packages/nexus-http/tests/Unit/Extract
git commit -m "feat(http): Extractor interface and built-in path extractors"
```

---

## Task 16: `path()` directive (variadic)

**Files:**
- Modify: `packages/nexus-http/src/Directive/functions.php`
- Create: `packages/nexus-http/tests/Unit/Directive/PathTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Extract\IntNumber;
use Monadial\Nexus\Http\Extract\UlidSegment;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;

final class PathTest extends TestCase
{
    #[Test]
    public function literal_only(): void
    {
        $route = path('orders', static fn() => get(static fn() => complete(['ok'])));
        self::assertNotNull(($route->run)(CtxFactory::with('GET', '/orders')));
    }

    #[Test]
    public function literal_with_extractor(): void
    {
        $route = path('orders', IntNumber::class, static fn(int $id) =>
            get(static fn() => complete(['id' => $id])),
        );
        $response = ($route->run)(CtxFactory::with('GET', '/orders/42'));
        self::assertSame('{"id":42}', (string) $response?->getBody());
    }

    #[Test]
    public function multiple_literals_and_extractors(): void
    {
        $route = path('tenant', UlidSegment::class, 'orders', IntNumber::class,
            static fn(Ulid $tid, int $oid) =>
                get(static fn() => complete(['t' => (string) $tid, 'o' => $oid])),
        );

        $ulid = '01HW00000000000000000000ZZ';
        $response = ($route->run)(CtxFactory::with('GET', "/tenant/{$ulid}/orders/7"));
        self::assertNotNull($response);
        self::assertStringContainsString('"o":7', (string) $response->getBody());
    }

    #[Test]
    public function rejects_when_path_does_not_match(): void
    {
        $route = path('orders', static fn() => get(static fn() => complete(['ok'])));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/payments')));
    }

    #[Test]
    public function throws_logic_exception_when_last_arg_is_not_callable(): void
    {
        $this->expectException(\LogicException::class);
        path('orders');
    }
}
```

- [ ] **Step 2: Append directive to `functions.php`**

```php
use Monadial\Nexus\Http\Extract\Extractor;

/**
 * Match a sequence of literal and/or extracted path segments.
 *
 * Last argument MUST be a callable that takes the extracted values (in order)
 * and returns a Route. Earlier arguments are either string literals or
 * class-strings of Extractor implementations.
 */
function path(mixed ...$args): Route
{
    if ($args === []) {
        throw new \LogicException('path() requires at least one segment and a child callable');
    }
    $child = array_pop($args);
    if (!is_callable($child)) {
        throw new \LogicException('path() last argument must be callable');
    }

    /** @var list<string|class-string<Extractor>> $segments */
    $segments = $args;

    return new Route(static function (RequestCtx $ctx) use ($segments, $child): ?ResponseInterface {
        $state = $ctx->pathState();
        $extracted = [];

        foreach ($segments as $segment) {
            $consume = $state->consumeAny();
            if ($consume === null) {
                return null;
            }
            [$current, $next] = $consume;

            if (is_string($segment) && !class_exists($segment)) {
                if ($segment !== $current) {
                    return null;
                }
            } else {
                /** @var Extractor<mixed> $instance */
                $instance = new $segment();
                $extracted[] = $instance->fromSegment($current);
            }

            $state = $next;
        }

        $route = $child(...$extracted);
        return ($route->run)($ctx->withPathState($state));
    });
}
```

- [ ] **Step 3: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Directive/PathTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http/src/Directive/functions.php packages/nexus-http/tests/Unit/Directive/PathTest.php
git commit -m "feat(http): variadic path() directive with literal and extractor segments"
```

---

## Task 17: Query, header, request directives

**Files:**
- Modify: `packages/nexus-http/src/Directive/functions.php`
- Create: `packages/nexus-http/tests/Unit/Directive/QueryHeaderTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Extract\IntNumber;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\extractRequest;
use function Monadial\Nexus\Http\header;
use function Monadial\Nexus\Http\optionalHeader;
use function Monadial\Nexus\Http\optionalQuery;
use function Monadial\Nexus\Http\query;

final class QueryHeaderTest extends TestCase
{
    private function ctxWith(string $uri, array $headers = []): \Monadial\Nexus\Http\DefaultRequestCtx
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return new \Monadial\Nexus\Http\DefaultRequestCtx(
            request: $request,
            params: [],
            system: $this->createStub(\Monadial\Nexus\Core\Actor\ActorSystem::class),
            registry: \Monadial\Nexus\Http\Marshalling\MarshallerRegistry::withDefaults(),
            logger: new \Psr\Log\NullLogger(),
        );
    }

    #[Test]
    public function query_passes_string_value(): void
    {
        $route = query('q', null, static fn(string $q) => complete(['q' => $q]));
        $response = ($route->run)($this->ctxWith('/?q=hello'));
        self::assertStringContainsString('"q":"hello"', (string) $response?->getBody());
    }

    #[Test]
    public function query_with_extractor_returns_typed(): void
    {
        $route = query('limit', IntNumber::class, static fn(int $n) => complete(['n' => $n]));
        $response = ($route->run)($this->ctxWith('/?limit=20'));
        self::assertStringContainsString('"n":20', (string) $response?->getBody());
    }

    #[Test]
    public function query_returns_null_when_missing(): void
    {
        $route = query('q', null, static fn(string $q) => complete(['q' => $q]));
        self::assertNull(($route->run)($this->ctxWith('/')));
    }

    #[Test]
    public function optional_query_passes_null_when_missing(): void
    {
        $route = optionalQuery('q', null, static fn(?string $q) => complete(['q' => $q]));
        $response = ($route->run)($this->ctxWith('/'));
        self::assertStringContainsString('"q":null', (string) $response?->getBody());
    }

    #[Test]
    public function header_extracts_header_value(): void
    {
        $route = header('X-Trace-Id', static fn(string $id) => complete(['id' => $id]));
        $response = ($route->run)($this->ctxWith('/', ['X-Trace-Id' => 'abc']));
        self::assertStringContainsString('"id":"abc"', (string) $response?->getBody());
    }

    #[Test]
    public function optional_header_passes_null_when_missing(): void
    {
        $route = optionalHeader('X-Y', static fn(?string $v) => complete(['v' => $v]));
        $response = ($route->run)($this->ctxWith('/'));
        self::assertStringContainsString('"v":null', (string) $response?->getBody());
    }

    #[Test]
    public function extract_request_passes_psr7_server_request(): void
    {
        $route = extractRequest(static fn($req) => complete(['m' => $req->getMethod()]));
        $response = ($route->run)($this->ctxWith('/'));
        self::assertStringContainsString('"m":"GET"', (string) $response?->getBody());
    }
}
```

- [ ] **Step 2: Append to `functions.php`**

```php
function query(string $name, ?string $extractorClass, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($name, $extractorClass, $child): ?ResponseInterface {
        $params = $ctx->request()->getQueryParams();
        if (!isset($params[$name]) || !is_string($params[$name])) {
            return null;
        }

        $value = $params[$name];
        $resolved = $extractorClass !== null
            ? (new $extractorClass())->fromSegment($value)
            : $value;

        $route = $child($resolved);
        return ($route->run)($ctx);
    });
}

function optionalQuery(string $name, ?string $extractorClass, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($name, $extractorClass, $child): ?ResponseInterface {
        $params = $ctx->request()->getQueryParams();
        $raw = isset($params[$name]) && is_string($params[$name]) ? $params[$name] : null;

        $resolved = match (true) {
            $raw === null               => null,
            $extractorClass === null    => $raw,
            default                     => (new $extractorClass())->fromSegment($raw),
        };

        $route = $child($resolved);
        return ($route->run)($ctx);
    });
}

function header(string $name, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($name, $child): ?ResponseInterface {
        $value = $ctx->request()->getHeaderLine($name);
        if ($value === '') {
            return null;
        }
        $route = $child($value);
        return ($route->run)($ctx);
    });
}

function optionalHeader(string $name, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($name, $child): ?ResponseInterface {
        $value = $ctx->request()->getHeaderLine($name);
        $route = $child($value === '' ? null : $value);
        return ($route->run)($ctx);
    });
}

function extractRequest(Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($child): ?ResponseInterface {
        $route = $child($ctx->request());
        return ($route->run)($ctx);
    });
}
```

- [ ] **Step 3: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Directive/QueryHeaderTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http/src/Directive/functions.php packages/nexus-http/tests/Unit/Directive/QueryHeaderTest.php
git commit -m "feat(http): query, optionalQuery, header, optionalHeader, extractRequest directives"
```

---

## Task 18: Body extractor directives — `rawBody`, `jsonBody`, `formBody`

**Files:**
- Modify: `packages/nexus-http/src/Directive/functions.php`
- Create: `packages/nexus-http/tests/Unit/Directive/BodyTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\formBody;
use function Monadial\Nexus\Http\jsonBody;
use function Monadial\Nexus\Http\rawBody;

final readonly class CreateOrderSample
{
    public function __construct(public string $sku, public int $qty) {}
}

final class BodyTest extends TestCase
{
    #[Test]
    public function raw_body_passes_string(): void
    {
        $route = rawBody(static fn(string $b) => complete(['len' => strlen($b)]));
        $response = ($route->run)(CtxFactory::with('POST', '/', 'hello'));
        self::assertStringContainsString('"len":5', (string) $response?->getBody());
    }

    #[Test]
    public function json_body_unmarshals_to_target(): void
    {
        $route = jsonBody(CreateOrderSample::class, static fn(CreateOrderSample $cmd) =>
            complete(['s' => $cmd->sku, 'q' => $cmd->qty]),
        );
        $response = ($route->run)(CtxFactory::with('POST', '/', '{"sku":"X","qty":3}'));
        self::assertStringContainsString('"s":"X"', (string) $response?->getBody());
        self::assertStringContainsString('"q":3', (string) $response?->getBody());
    }

    #[Test]
    public function form_body_passes_parsed_array(): void
    {
        $route = formBody(static fn(array $form) => complete($form));
        $response = ($route->run)(CtxFactory::with('POST', '/', 'a=1&b=2'));
        self::assertStringContainsString('"a":"1"', (string) $response?->getBody());
        self::assertStringContainsString('"b":"2"', (string) $response?->getBody());
    }
}
```

- [ ] **Step 2: Append directives**

```php
use Monadial\Nexus\Http\Rejection\BodyParseException;

function rawBody(Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($child): ?ResponseInterface {
        $body = (string) $ctx->request()->getBody();
        $route = $child($body);
        return ($route->run)($ctx);
    });
}

/**
 * @template T of object
 * @param class-string<T> $targetType
 */
function jsonBody(string $targetType, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($targetType, $child): ?ResponseInterface {
        $body = (string) $ctx->request()->getBody();
        $marshaller = $ctx->marshallerFor(\Monadial\Nexus\Http\Marshalling\MediaType::parse('application/json'));
        $value = $marshaller->unmarshal($body, $targetType);
        $route = $child($value);
        return ($route->run)($ctx);
    });
}

function formBody(Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($child): ?ResponseInterface {
        $body = (string) $ctx->request()->getBody();
        parse_str($body, $parsed);
        if (!is_array($parsed)) {
            throw new BodyParseException('failed to parse form body');
        }
        $route = $child($parsed);
        return ($route->run)($ctx);
    });
}
```

(`formBody()` accepts only an `array` callback in this phase — typed form-body unmarshalling can be added later.)

- [ ] **Step 3: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Directive/BodyTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http/src/Directive/functions.php packages/nexus-http/tests/Unit/Directive/BodyTest.php
git commit -m "feat(http): rawBody, jsonBody, formBody directives"
```

---

## Task 19: PSR-15 middleware adapter directives

**Files:**
- Modify: `packages/nexus-http/src/Directive/functions.php`
- Create: `packages/nexus-http/src/Directive/RouteHandler.php`
- Create: `packages/nexus-http/tests/Unit/Directive/MiddlewareTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\useMiddleware;
use function Monadial\Nexus\Http\useMiddlewares;

final class HeaderStampMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $name, private readonly string $value) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withHeader($this->name, $this->value);
    }
}

final class MiddlewareTest extends TestCase
{
    #[Test]
    public function single_middleware_wraps_response(): void
    {
        $route = useMiddleware(
            new HeaderStampMiddleware('X-Trace', 'on'),
            static fn() => complete(['ok' => true]),
        );

        $response = ($route->run)(CtxFactory::with('GET', '/'));
        self::assertSame('on', $response?->getHeaderLine('X-Trace'));
    }

    #[Test]
    public function multiple_middlewares_apply_in_order(): void
    {
        $route = useMiddlewares([
            new HeaderStampMiddleware('X-A', '1'),
            new HeaderStampMiddleware('X-B', '2'),
        ], static fn() => complete(['ok' => true]));

        $response = ($route->run)(CtxFactory::with('GET', '/'));
        self::assertSame('1', $response?->getHeaderLine('X-A'));
        self::assertSame('2', $response?->getHeaderLine('X-B'));
    }
}
```

- [ ] **Step 2: Implement adapter `RouteHandler`**

`packages/nexus-http/src/Directive/RouteHandler.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Directive;

use Monadial\Nexus\Http\RequestCtx;
use Monadial\Nexus\Http\Routing\Route;
use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps a Route + RequestCtx into a PSR-15 RequestHandlerInterface so PSR-15
 * middlewares can adapt the request before the route runs.
 */
final class RouteHandler implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middlewares */
    public function __construct(
        private readonly Route $route,
        private readonly RequestCtx $ctx,
        private readonly array $middlewares = [],
        private readonly int $offset = 0,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (isset($this->middlewares[$this->offset])) {
            return $this->middlewares[$this->offset]->process(
                $request,
                new self($this->route, $this->ctx, $this->middlewares, $this->offset + 1),
            );
        }

        $ctx = $this->ctx instanceof \Monadial\Nexus\Http\DefaultRequestCtx
            ? new \Monadial\Nexus\Http\DefaultRequestCtx(
                request: $request,
                params: $this->ctx->params,
                system: $this->ctx->system,
                registry: $this->ctx->registry,
                logger: $this->ctx->logger,
            )
            : $this->ctx;

        $response = ($this->route->run)($ctx);
        return $response ?? new Response(404);
    }
}
```

- [ ] **Step 3: Append directives**

```php
use Monadial\Nexus\Http\Directive\RouteHandler;
use Psr\Http\Server\MiddlewareInterface;

function useMiddleware(MiddlewareInterface $middleware, Closure $child): Route
{
    return useMiddlewares([$middleware], $child);
}

/** @param list<MiddlewareInterface> $middlewares */
function useMiddlewares(array $middlewares, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($middlewares, $child): ResponseInterface {
        $route = $child();
        $handler = new RouteHandler($route, $ctx, $middlewares);
        return $handler->handle($ctx->request());
    });
}
```

- [ ] **Step 4: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Directive/MiddlewareTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Directive/RouteHandler.php packages/nexus-http/src/Directive/functions.php packages/nexus-http/tests/Unit/Directive/MiddlewareTest.php
git commit -m "feat(http): useMiddleware and useMiddlewares directives via PSR-15 adapter"
```

---

## Task 20: `mapResponse`, `mapRejection` directives

**Files:**
- Modify: `packages/nexus-http/src/Directive/functions.php`
- Create: `packages/nexus-http/tests/Unit/Directive/MapTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Rejection\RouteRejection;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\mapRejection;
use function Monadial\Nexus\Http\mapResponse;
use function Monadial\Nexus\Http\reject;

final class MapTest extends TestCase
{
    #[Test]
    public function map_response_transforms_completed_response(): void
    {
        $route = mapResponse(
            static fn(\Psr\Http\Message\ResponseInterface $r) => $r->withHeader('X-Wrap', 'yes'),
            static fn() => complete(['ok' => true]),
        );

        $response = ($route->run)(CtxFactory::with('GET', '/'));
        self::assertSame('yes', $response?->getHeaderLine('X-Wrap'));
    }

    #[Test]
    public function map_rejection_converts_thrown_rejection_to_response(): void
    {
        $route = mapRejection(
            static fn(RouteRejection $r) => new Response($r->status, [], 'mapped'),
            static fn() => reject(new RouteRejection('forbidden', 'no', 403)),
        );

        $response = ($route->run)(CtxFactory::with('GET', '/'));
        self::assertSame(403, $response?->getStatusCode());
        self::assertSame('mapped', (string) $response?->getBody());
    }
}
```

- [ ] **Step 2: Append directives**

```php
function mapResponse(Closure $transform, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($transform, $child): ?ResponseInterface {
        $route = $child();
        $response = ($route->run)($ctx);
        if ($response === null) {
            return null;
        }
        return $transform($response);
    });
}

function mapRejection(Closure $transform, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($transform, $child): ?ResponseInterface {
        $route = $child();
        try {
            return ($route->run)($ctx);
        } catch (RouteRejection $rejection) {
            return $transform($rejection);
        }
    });
}
```

- [ ] **Step 3: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Directive/MapTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http/src/Directive/functions.php packages/nexus-http/tests/Unit/Directive/MapTest.php
git commit -m "feat(http): mapResponse and mapRejection directives"
```

---

## Task 21: Built-in middlewares — `RequestIdMiddleware`, `BearerTokenMiddleware`, `LoggingMiddleware`

**Files:**
- Create: `packages/nexus-http/src/Middleware/RequestIdMiddleware.php`
- Create: `packages/nexus-http/src/Middleware/BearerTokenMiddleware.php`
- Create: `packages/nexus-http/src/Middleware/LoggingMiddleware.php`
- Create: `packages/nexus-http/tests/Unit/Middleware/MiddlewareSuiteTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Middleware\BearerTokenMiddleware;
use Monadial\Nexus\Http\Middleware\LoggingMiddleware;
use Monadial\Nexus\Http\Middleware\RequestIdMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;

final class CapturingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $seen = null;
    public function __construct(private readonly int $status = 200) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->seen = $request;
        return new Response($this->status);
    }
}

final class CapturingLogger extends AbstractLogger
{
    public array $records = [];
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

final class MiddlewareSuiteTest extends TestCase
{
    #[Test]
    public function request_id_uses_existing_header_when_present(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/')->withHeader('X-Request-Id', 'abc');
        $handler = new CapturingHandler();
        $response = (new RequestIdMiddleware())->process($request, $handler);
        self::assertSame('abc', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('abc', $handler->seen?->getHeaderLine('X-Request-Id'));
    }

    #[Test]
    public function request_id_generates_when_missing(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $handler = new CapturingHandler();
        $response = (new RequestIdMiddleware())->process($request, $handler);
        self::assertNotEmpty($response->getHeaderLine('X-Request-Id'));
    }

    #[Test]
    public function bearer_token_rejects_when_header_missing(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $response = (new BearerTokenMiddleware(['secret']))->process($request, new CapturingHandler());
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function bearer_token_passes_when_token_valid(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer secret');
        $response = (new BearerTokenMiddleware(['secret']))->process($request, new CapturingHandler());
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function logging_writes_access_record(): void
    {
        $logger = new CapturingLogger();
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/orders/42');
        (new LoggingMiddleware($logger))->process($request, new CapturingHandler(200));
        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
    }
}
```

- [ ] **Step 2: Implement middlewares**

`packages/nexus-http/src/Middleware/RequestIdMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(public string $headerName = 'X-Request-Id') {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine($this->headerName);
        if ($id === '') {
            $id = (string) new Ulid();
            $request = $request->withHeader($this->headerName, $id);
        }

        $response = $handler->handle($request);
        return $response->withHeader($this->headerName, $id);
    }
}
```

`packages/nexus-http/src/Middleware/BearerTokenMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class BearerTokenMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedTokens */
    public function __construct(
        public array $allowedTokens,
        public string $headerName = 'Authorization',
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine($this->headerName);
        if (!str_starts_with($header, 'Bearer ')) {
            return $this->reject('missing_token');
        }

        $token = substr($header, 7);
        foreach ($this->allowedTokens as $valid) {
            if (hash_equals($valid, $token)) {
                return $handler->handle($request);
            }
        }

        return $this->reject('invalid_token');
    }

    private function reject(string $code): Response
    {
        return (new Response(401, ['Content-Type' => 'application/json']))
            ->withBody(\Nyholm\Psr7\Stream::create(json_encode(['error' => $code], JSON_THROW_ON_ERROR)));
    }
}
```

`packages/nexus-http/src/Middleware/LoggingMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(public LoggerInterface $logger) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = (int) (microtime(true) * 1000);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->logger->error('http_request_failed', [
                'method'   => $request->getMethod(),
                'path'     => $request->getUri()->getPath(),
                'error'    => $e->getMessage(),
                'class'    => $e::class,
            ]);
            throw $e;
        }

        $level = match (true) {
            $response->getStatusCode() >= 500 => 'error',
            $response->getStatusCode() >= 400 => 'notice',
            default                            => 'info',
        };

        $this->logger->log($level, 'http_request', [
            'method'      => $request->getMethod(),
            'path'        => $request->getUri()->getPath(),
            'status'      => $response->getStatusCode(),
            'durationMs'  => max(0, (int) (microtime(true) * 1000) - $start),
        ]);

        return $response;
    }
}
```

- [ ] **Step 3: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Middleware/MiddlewareSuiteTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http/src/Middleware packages/nexus-http/tests/Unit/Middleware
git commit -m "feat(http): RequestIdMiddleware, BearerTokenMiddleware, LoggingMiddleware"
```

---

## Task 22: `ErrorMapper` interface + `DefaultErrorMapper`

**Files:**
- Create: `packages/nexus-http/src/Error/ErrorMapper.php`
- Create: `packages/nexus-http/src/Error/DefaultErrorMapper.php`
- Create: `packages/nexus-http/tests/Unit/Error/DefaultErrorMapperTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Error;

use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Http\Error\DefaultErrorMapper;
use Monadial\Nexus\Http\Rejection\BodyParseException;
use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Monadial\Nexus\Http\Rejection\MethodNotAllowedRejection;
use Monadial\Nexus\Http\Rejection\RouteNotFoundRejection;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefaultErrorMapperTest extends TestCase
{
    private DefaultErrorMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new DefaultErrorMapper();
    }

    #[Test]
    public function maps_extractor_rejection_to_400(): void
    {
        $response = $this->mapper->map(
            new ExtractorRejection('orders/abc', 'expected integer'),
            CtxFactory::with('GET', '/'),
        );
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('extractor_failed', (string) $response->getBody());
    }

    #[Test]
    public function maps_body_parse_to_400(): void
    {
        $response = $this->mapper->map(new BodyParseException('bad json'), CtxFactory::with('POST', '/'));
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function maps_not_found_to_404(): void
    {
        $response = $this->mapper->map(new RouteNotFoundRejection('/missing'), CtxFactory::with('GET', '/missing'));
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function maps_method_not_allowed_to_405_with_allow_header(): void
    {
        $response = $this->mapper->map(
            new MethodNotAllowedRejection('PATCH', ['GET', 'POST']),
            CtxFactory::with('PATCH', '/'),
        );
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function maps_ask_timeout_to_504(): void
    {
        $response = $this->mapper->map(new AskTimeoutException('orders'), CtxFactory::with('GET', '/'));
        self::assertSame(504, $response->getStatusCode());
    }

    #[Test]
    public function maps_mailbox_closed_to_503(): void
    {
        $response = $this->mapper->map(new MailboxClosedException('mailbox closed'), CtxFactory::with('GET', '/'));
        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function maps_unknown_throwable_to_500(): void
    {
        $response = $this->mapper->map(new \RuntimeException('boom'), CtxFactory::with('GET', '/'));
        self::assertSame(500, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Implement interface**

`packages/nexus-http/src/Error/ErrorMapper.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Error;

use Monadial\Nexus\Http\RequestCtx;
use Psr\Http\Message\ResponseInterface;
use Throwable;

interface ErrorMapper
{
    public function map(Throwable $error, RequestCtx $ctx): ResponseInterface;
}
```

- [ ] **Step 3: Implement default mapper**

`packages/nexus-http/src/Error/DefaultErrorMapper.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Error;

use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Exception\MaxRetriesExceededException;
use Monadial\Nexus\Http\Rejection\MethodNotAllowedRejection;
use Monadial\Nexus\Http\Rejection\RouteRejection;
use Monadial\Nexus\Http\RequestCtx;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Override;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class DefaultErrorMapper implements ErrorMapper
{
    #[Override]
    public function map(Throwable $error, RequestCtx $ctx): ResponseInterface
    {
        [$status, $code, $message, $extraHeaders] = match (true) {
            $error instanceof MethodNotAllowedRejection => [
                $error->status, $error->code, $error->message,
                ['Allow' => implode(', ', $error->allowed)],
            ],
            $error instanceof RouteRejection           => [$error->status, $error->code, $error->message, []],
            $error instanceof AskTimeoutException      => [504, 'ask_timeout', $error->getMessage(), []],
            $error instanceof MailboxClosedException   => [503, 'mailbox_closed', $error->getMessage(), []],
            $error instanceof MaxRetriesExceededException => [503, 'max_retries', $error->getMessage(), []],
            default                                    => [500, 'internal_error', 'internal server error', []],
        };

        $marshaller = $ctx->marshallerFor($ctx->negotiate());
        $payload = [
            'error'     => $code,
            'message'   => $message,
            'requestId' => $ctx->request()->getHeaderLine('X-Request-Id'),
        ];
        $body = $marshaller->marshal($payload);

        $response = (new Response($status))
            ->withHeader('Content-Type', (string) $marshaller->mediaType())
            ->withBody(Stream::create($body));

        foreach ($extraHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Error/DefaultErrorMapperTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Error packages/nexus-http/tests/Unit/Error
git commit -m "feat(http): ErrorMapper interface and DefaultErrorMapper"
```

---

## Task 23: `DispatchTrie` + `RouteCompiler` (boot-time compilation)

**Files:**
- Create: `packages/nexus-http/src/Routing/DispatchTrie.php`
- Create: `packages/nexus-http/src/Routing/RouteCompiler.php`
- Create: `packages/nexus-http/tests/Unit/Routing/DispatchTrieTest.php`

The compiled trie is a top-of-router optimization for the *common* case of method-prefixed routes. For Phase 1 we build a thin trie that:

- Indexes routes by literal path prefix
- Falls back to running the full `concat()` tree if no prefix match (so directive flexibility is preserved)
- Surfaces 405 (method-not-allowed) when a path matches but no method does

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Monadial\Nexus\Http\Routing\RouteCompiler;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\concat;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;
use function Monadial\Nexus\Http\post;

final class DispatchTrieTest extends TestCase
{
    #[Test]
    public function dispatches_to_matching_method_and_path(): void
    {
        $tree = concat(
            get(path('orders', static fn() => complete(['list']))),
            post(path('orders', static fn() => complete(['created']))),
        );
        $trie = RouteCompiler::compile($tree);

        $get = $trie->dispatch(CtxFactory::with('GET', '/orders'));
        self::assertSame('{"0":"list"}', (string) $get?->getBody());

        $post = $trie->dispatch(CtxFactory::with('POST', '/orders'));
        self::assertSame('{"0":"created"}', (string) $post?->getBody());
    }

    #[Test]
    public function returns_null_when_path_does_not_match(): void
    {
        $tree = get(path('orders', static fn() => complete(['ok'])));
        $trie = RouteCompiler::compile($tree);
        self::assertNull($trie->dispatch(CtxFactory::with('GET', '/missing')));
    }
}
```

- [ ] **Step 2: Implement `DispatchTrie`**

`packages/nexus-http/src/Routing/DispatchTrie.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use Monadial\Nexus\Http\RequestCtx;
use Psr\Http\Message\ResponseInterface;

/**
 * Phase-1 trie: a thin wrapper around a single Route value (the result of concat(...)).
 *
 * Method/path optimization (real prefix tree) is deferred until benchmarking shows it's needed.
 * This keeps directive semantics authoritative while still providing the trie API for downstream
 * code that wants a single dispatch entry point.
 */
final readonly class DispatchTrie
{
    public function __construct(public Route $root) {}

    public function dispatch(RequestCtx $ctx): ?ResponseInterface
    {
        return ($this->root->run)($ctx);
    }
}
```

- [ ] **Step 3: Implement `RouteCompiler`**

`packages/nexus-http/src/Routing/RouteCompiler.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

final readonly class RouteCompiler
{
    public static function compile(Route $tree): DispatchTrie
    {
        return new DispatchTrie($tree);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Routing/DispatchTrieTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Routing/DispatchTrie.php packages/nexus-http/src/Routing/RouteCompiler.php packages/nexus-http/tests/Unit/Routing/DispatchTrieTest.php
git commit -m "feat(http): DispatchTrie wrapper and RouteCompiler entry point"
```

---

## Task 24: `RouteTestKit` (Layer 1)

**Files:**
- Create: `packages/nexus-http-testkit/src/RouteTestKit.php`
- Create: `packages/nexus-http-testkit/src/RouteResult.php`
- Create: `packages/nexus-http-testkit/tests/Unit/RouteTestKitTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\TestKit\Tests\Unit;

use Monadial\Nexus\Http\TestKit\RouteTestKit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;

final class RouteTestKitTest extends TestCase
{
    #[Test]
    public function builds_get_request_and_runs_route(): void
    {
        $route = get(path('hello', static fn() => complete(['msg' => 'hi'])));

        $result = RouteTestKit::route($route)
            ->get('/hello')
            ->run();

        self::assertSame(200, $result->status());
        self::assertSame(['msg' => 'hi'], $result->jsonBody());
    }

    #[Test]
    public function returns_404_when_route_rejects(): void
    {
        $route = get(path('hello', static fn() => complete([])));

        $result = RouteTestKit::route($route)
            ->get('/missing')
            ->run();

        self::assertSame(404, $result->status());
    }

    #[Test]
    public function passes_request_body_for_post(): void
    {
        $route = \Monadial\Nexus\Http\post(\Monadial\Nexus\Http\rawBody(static fn(string $body) =>
            complete(['len' => strlen($body)]),
        ));

        $result = RouteTestKit::route($route)
            ->post('/', '{"a":1}')
            ->run();

        self::assertSame(200, $result->status());
        self::assertSame(['len' => 7], $result->jsonBody());
    }
}
```

- [ ] **Step 2: Implement helper types**

`packages/nexus-http-testkit/src/RouteResult.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\TestKit;

use Psr\Http\Message\ResponseInterface;

final readonly class RouteResult
{
    public function __construct(public ResponseInterface $response) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function rawBody(): string
    {
        return (string) $this->response->getBody();
    }

    /** @return array<array-key, mixed> */
    public function jsonBody(): array
    {
        $decoded = json_decode($this->rawBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('response body is not a JSON object/array');
        }
        return $decoded;
    }
}
```

`packages/nexus-http-testkit/src/RouteTestKit.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\TestKit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Error\DefaultErrorMapper;
use Monadial\Nexus\Http\Error\ErrorMapper;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class RouteTestKit
{
    private ServerRequestInterface $request;
    private MarshallerRegistry $registry;
    private LoggerInterface $logger;
    private ?ActorSystem $system = null;
    private ErrorMapper $errorMapper;

    public function __construct(public readonly Route $route)
    {
        $factory = new Psr17Factory();
        $this->request = $factory->createServerRequest('GET', '/');
        $this->registry = MarshallerRegistry::withDefaults();
        $this->logger = new NullLogger();
        $this->errorMapper = new DefaultErrorMapper();
    }

    public static function route(Route $route): self
    {
        return new self($route);
    }

    public function withSystem(ActorSystem $system): self
    {
        $this->system = $system;
        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->request = $this->request->withHeader($name, $value);
        return $this;
    }

    public function get(string $uri): self
    {
        $this->request = $this->request->withMethod('GET')->withUri((new Psr17Factory())->createUri($uri));
        return $this;
    }

    public function post(string $uri, string $body = ''): self
    {
        $factory = new Psr17Factory();
        $this->request = $this->request->withMethod('POST')
            ->withUri($factory->createUri($uri))
            ->withBody($factory->createStream($body));
        return $this;
    }

    public function run(): RouteResult
    {
        $system = $this->system ?? ActorSystem::create('testkit', new StepRuntime());

        $ctx = new DefaultRequestCtx(
            request: $this->request,
            params: [],
            system: $system,
            registry: $this->registry,
            logger: $this->logger,
        );

        try {
            $response = ($this->route->run)($ctx) ?? new Response(404);
        } catch (Throwable $e) {
            $response = $this->errorMapper->map($e, $ctx);
        }

        return new RouteResult($response);
    }
}
```

- [ ] **Step 3: Run tests**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http-testkit/tests/Unit/RouteTestKitTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http-testkit/src packages/nexus-http-testkit/tests
git commit -m "feat(http-testkit): RouteTestKit Layer 1 (synthetic PSR-7 routing)"
```

---

## Task 25: `RouteTestKit::withSystem()` for actor-aware Layer 2

**Files:**
- Modify: `packages/nexus-http-testkit/src/RouteTestKit.php` (already done in Task 24)
- Create: `packages/nexus-http-testkit/tests/Unit/RouteTestKitWithSystemTest.php`

- [ ] **Step 1: Write failing test using `StepRuntime`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\TestKit\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\TestKit\RouteTestKit;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;

final readonly class EchoQuery
{
    public function __construct(public string $name) {}
}

final readonly class EchoReply
{
    public function __construct(public string $name) {}
}

final class RouteTestKitWithSystemTest extends TestCase
{
    #[Test]
    public function ask_via_real_step_runtime_round_trips(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('test', $runtime);

        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            if ($msg instanceof EchoQuery) {
                $ctx->sender()?->tell(new EchoReply($msg->name));
            }
            return Behavior::same();
        });
        $system->spawn(Props::fromBehavior($behavior), 'echo');

        $route = get(path('echo', \Monadial\Nexus\Http\Extract\StringSegment::class,
            static fn(string $name) =>
                complete(static fn($ctx) => $ctx->ask('echo', new EchoQuery($name))),
        ));

        $result = RouteTestKit::route($route)
            ->withSystem($system)
            ->get('/echo/world')
            ->run();

        self::assertSame(200, $result->status());
    }
}
```

(Adapt the spawn/ask shape to whatever `ActorSystem::actorFor` and `ActorRef::tell` expose in the current core; the test is about the integration point — hooking a real system into the testkit.)

- [ ] **Step 2: Run test (verify the path through `withSystem` works)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http-testkit/tests/Unit/RouteTestKitWithSystemTest.php`
Expected: PASS — testkit propagates the real `ActorSystem` into `DefaultRequestCtx`.

If the test surfaces an integration bug (e.g., `ask` semantics not yet matching `RequestCtx::ask`), fix `DefaultRequestCtx::ask` to align with the real `ActorRef::ask` signature.

- [ ] **Step 3: Commit**

```bash
git add packages/nexus-http-testkit/tests/Unit/RouteTestKitWithSystemTest.php
git commit -m "test(http-testkit): integrate RouteTestKit with real ActorSystem via StepRuntime"
```

---

## Task 26: `SwooleRequestConverter`

**Files:**
- Create: `packages/nexus-http-swoole/src/SwooleRequestConverter.php`
- Create: `packages/nexus-http-swoole/tests/Unit/SwooleRequestConverterTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole\Tests\Unit;

use Monadial\Nexus\Http\Swoole\SwooleRequestConverter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwRequest;

final class SwooleRequestConverterTest extends TestCase
{
    #[Test]
    public function converts_get_request_with_query_and_headers(): void
    {
        $sw = new SwRequest();
        $sw->server = [
            'request_method' => 'GET',
            'request_uri'    => '/orders',
            'query_string'   => 'limit=20',
            'remote_addr'    => '127.0.0.1',
        ];
        $sw->header = ['accept' => 'application/json', 'host' => 'localhost'];
        $sw->get = ['limit' => '20'];

        $psr = (new SwooleRequestConverter())->toPsrRequest($sw);

        self::assertSame('GET', $psr->getMethod());
        self::assertSame('/orders', $psr->getUri()->getPath());
        self::assertSame('application/json', $psr->getHeaderLine('Accept'));
        self::assertSame(['limit' => '20'], $psr->getQueryParams());
    }

    #[Test]
    public function converts_post_with_body(): void
    {
        $sw = new SwRequest();
        $sw->server = ['request_method' => 'POST', 'request_uri' => '/orders'];
        $sw->header = ['content-type' => 'application/json'];
        $sw->rawContent = static fn() => '{"sku":"X"}';

        $psr = (new SwooleRequestConverter())->toPsrRequest($sw);
        self::assertSame('{"sku":"X"}', (string) $psr->getBody());
    }
}
```

- [ ] **Step 2: Implement**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Request as SwRequest;

final readonly class SwooleRequestConverter
{
    public function __construct(private Psr17Factory $factory = new Psr17Factory()) {}

    public function toPsrRequest(SwRequest $sw): ServerRequestInterface
    {
        $method = $sw->server['request_method'] ?? 'GET';
        $path   = $sw->server['request_uri'] ?? '/';
        $query  = $sw->server['query_string'] ?? '';
        $uri    = $this->factory->createUri($path . ($query !== '' ? "?{$query}" : ''));

        $request = $this->factory->createServerRequest($method, $uri, $sw->server ?? []);

        foreach ($sw->header ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if (!empty($sw->cookie)) {
            $request = $request->withCookieParams($sw->cookie);
        }

        if (!empty($sw->get)) {
            $request = $request->withQueryParams($sw->get);
        }

        if (!empty($sw->post)) {
            $request = $request->withParsedBody($sw->post);
        }

        $raw = is_callable($sw->rawContent ?? null) ? ($sw->rawContent)() : ($sw->rawContent ?? '');
        if ($raw !== '') {
            $request = $request->withBody($this->factory->createStream($raw));
        }

        return $request;
    }
}
```

- [ ] **Step 3: Run test**

Run: `docker compose exec php-swoole vendor/bin/phpunit packages/nexus-http-swoole/tests/Unit/SwooleRequestConverterTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http-swoole/src/SwooleRequestConverter.php packages/nexus-http-swoole/tests/Unit/SwooleRequestConverterTest.php
git commit -m "feat(http-swoole): SwooleRequestConverter (Swoole Request -> PSR-7)"
```

---

## Task 27: `SwooleResponseEmitter`

**Files:**
- Create: `packages/nexus-http-swoole/src/SwooleResponseEmitter.php`
- Create: `packages/nexus-http-swoole/tests/Unit/SwooleResponseEmitterTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole\Tests\Unit;

use Monadial\Nexus\Http\Swoole\SwooleResponseEmitter;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakeSwooleResponse
{
    public int $status = 0;
    public array $headers = [];
    public string $body = '';

    public function status(int $code): bool { $this->status = $code; return true; }
    public function header(string $name, string $value): bool { $this->headers[$name] = $value; return true; }
    public function end(string $body = ''): bool { $this->body = $body; return true; }
}

final class SwooleResponseEmitterTest extends TestCase
{
    #[Test]
    public function emits_status_headers_and_body(): void
    {
        $psr = new Response(201, ['Content-Type' => 'application/json']);
        $psr = $psr->withBody(Stream::create('{"ok":true}'));

        $sw = new FakeSwooleResponse();
        (new SwooleResponseEmitter())->emit($psr, $sw);

        self::assertSame(201, $sw->status);
        self::assertSame('application/json', $sw->headers['Content-Type']);
        self::assertSame('{"ok":true}', $sw->body);
    }
}
```

- [ ] **Step 2: Implement**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole;

use Psr\Http\Message\ResponseInterface;

final readonly class SwooleResponseEmitter
{
    /** @param object{status: callable, header: callable, end: callable} $sw */
    public function emit(ResponseInterface $psr, object $sw): void
    {
        $sw->status($psr->getStatusCode());
        foreach ($psr->getHeaders() as $name => $values) {
            $sw->header($name, implode(', ', $values));
        }
        $sw->end((string) $psr->getBody());
    }
}
```

- [ ] **Step 3: Run test**

Run: `docker compose exec php-swoole vendor/bin/phpunit packages/nexus-http-swoole/tests/Unit/SwooleResponseEmitterTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http-swoole/src/SwooleResponseEmitter.php packages/nexus-http-swoole/tests/Unit/SwooleResponseEmitterTest.php
git commit -m "feat(http-swoole): SwooleResponseEmitter (PSR-7 -> Swoole response)"
```

---

## Task 28: `HttpServerBootstrap::dev()` (T3)

**Files:**
- Create: `packages/nexus-http-swoole/src/HttpServerBootstrap.php`
- Create: `tests/Integration/Swoole/HttpDevServerTest.php`

- [ ] **Step 1: Implement `HttpServerBootstrap` (T3 only)**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Error\DefaultErrorMapper;
use Monadial\Nexus\Http\Error\ErrorMapper;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Routing\DispatchTrie;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Http\Routing\RouteCompiler;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Nyholm\Psr7\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Http\Request as SwRequest;
use Swoole\Http\Response as SwResponse;
use Swoole\Http\Server as SwServer;

final class HttpServerBootstrap
{
    private string $host = '127.0.0.1';
    private int $port = 8080;
    private MarshallerRegistry $registry;
    private ?Closure $onSystemReady = null;
    private LoggerInterface $logger;
    private ErrorMapper $errorMapper;
    private SwooleRequestConverter $converter;
    private SwooleResponseEmitter $emitter;

    private function __construct(public readonly Route $routes)
    {
        $this->registry     = MarshallerRegistry::withDefaults();
        $this->logger       = new NullLogger();
        $this->errorMapper  = new DefaultErrorMapper();
        $this->converter    = new SwooleRequestConverter();
        $this->emitter      = new SwooleResponseEmitter();
    }

    public static function dev(Route $routes): self
    {
        return new self($routes);
    }

    public function host(string $host): self    { $this->host = $host; return $this; }
    public function port(int $port): self       { $this->port = $port; return $this; }
    public function logger(LoggerInterface $l): self { $this->logger = $l; return $this; }
    public function errorMapper(ErrorMapper $m): self { $this->errorMapper = $m; return $this; }
    public function marshallers(Closure $configure): self
    {
        $configure($this->registry);
        return $this;
    }
    public function onSystemReady(Closure $cb): self { $this->onSystemReady = $cb; return $this; }

    public function run(): void
    {
        $runtime = new SwooleRuntime();
        $system  = ActorSystem::create('http-dev', $runtime);
        ($this->onSystemReady)?->__invoke($system);

        $trie    = RouteCompiler::compile($this->routes);
        $server  = new SwServer($this->host, $this->port, SWOOLE_BASE);
        $server->set(['enable_coroutine' => true]);

        $server->on('request', function (SwRequest $req, SwResponse $res) use ($trie, $system): void {
            Coroutine::create(function () use ($req, $res, $trie, $system): void {
                $psr = $this->converter->toPsrRequest($req);
                $ctx = new DefaultRequestCtx(
                    request: $psr,
                    params: [],
                    system: $system,
                    registry: $this->registry,
                    logger: $this->logger,
                );

                try {
                    $response = $trie->dispatch($ctx) ?? new Response(404);
                } catch (\Throwable $e) {
                    $response = $this->errorMapper->map($e, $ctx);
                }

                $this->emitter->emit($response, $res);
            });
        });

        $server->on('start', fn(): mixed => $this->logger->info('http_dev_server_ready', [
            'host' => $this->host,
            'port' => $this->port,
        ]));

        $server->start();
    }
}
```

- [ ] **Step 2: Write integration test**

`tests/Integration/Swoole/HttpDevServerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Swoole;

use Monadial\Nexus\Http\Swoole\HttpServerBootstrap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;
use function Swoole\Coroutine\run;

final class HttpDevServerTest extends TestCase
{
    #[Test]
    public function dev_server_serves_a_basic_route(): void
    {
        $port = random_int(20000, 30000);
        $body = null;

        run(function () use ($port, &$body): void {
            \Swoole\Coroutine::create(function () use ($port): void {
                $route = get(path('hello', static fn() => complete(['msg' => 'hi'])));
                HttpServerBootstrap::dev($route)
                    ->host('127.0.0.1')
                    ->port($port)
                    ->run();
            });

            \Swoole\Coroutine::sleep(0.2); // let server bind

            \Swoole\Coroutine::create(function () use ($port, &$body): void {
                $client = new Client('127.0.0.1', $port);
                $client->setHeaders(['Accept' => 'application/json']);
                $client->get('/hello');
                $body = $client->body;
                $client->close();

                \Swoole\Process::kill(getmypid(), SIGTERM);
            });
        });

        self::assertSame('{"msg":"hi"}', $body);
    }
}
```

(This test runs the real Swoole HTTP server in-process. Tear-down via `kill SIGTERM` is brutal but workable for an integration suite.)

- [ ] **Step 3: Run integration test**

Run: `docker compose exec php-swoole vendor/bin/phpunit tests/Integration/Swoole/HttpDevServerTest.php`
Expected: PASS — "msg":"hi" round-trip via real Swoole.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http-swoole/src/HttpServerBootstrap.php tests/Integration/Swoole/HttpDevServerTest.php
git commit -m "feat(http-swoole): HttpServerBootstrap::dev (T3 single-coroutine server)"
```

---

## Task 29: Reference example app — `examples/http-orders/`

**Files:**
- Create: `examples/http-orders/composer.json`
- Create: `examples/http-orders/README.md`
- Create: `examples/http-orders/src/Domain/Order.php`
- Create: `examples/http-orders/src/Domain/OrderActor.php`
- Create: `examples/http-orders/src/Domain/CreateOrder.php`
- Create: `examples/http-orders/src/Domain/GetOrder.php`
- Create: `examples/http-orders/src/Domain/DeleteOrder.php`
- Create: `examples/http-orders/src/routes.php`
- Create: `examples/http-orders/bin/serve.php`

The example demonstrates the full Phase 1 DSL surface: `path`, `get`, `post`, `delete`, `pathPrefix`, `concat`, `complete`, `jsonBody`, `useMiddleware` (RequestId + Logging).

- [ ] **Step 1: Domain types**

`examples/http-orders/src/Domain/Order.php`:
```php
<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

final readonly class Order
{
    public function __construct(public int $id, public string $sku, public int $qty) {}
}
```

`examples/http-orders/src/Domain/CreateOrder.php`:
```php
<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

final readonly class CreateOrder
{
    public function __construct(public string $sku, public int $qty) {}
}
```

`examples/http-orders/src/Domain/GetOrder.php`:
```php
<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

final readonly class GetOrder
{
    public function __construct(public int $id) {}
}
```

`examples/http-orders/src/Domain/DeleteOrder.php`:
```php
<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

final readonly class DeleteOrder
{
    public function __construct(public int $id) {}
}
```

`examples/http-orders/src/Domain/OrderActor.php`:
```php
<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;

final class OrderActor
{
    /** @return Behavior<object> */
    public static function create(): Behavior
    {
        return Behavior::withState(['nextId' => 1, 'orders' => []],
            static function (ActorContext $ctx, object $msg, array $state): BehaviorWithState {
                if ($msg instanceof CreateOrder) {
                    $id = $state['nextId'];
                    $order = new Order($id, $msg->sku, $msg->qty);
                    $ctx->sender()?->tell($order);
                    return BehaviorWithState::next([
                        'nextId' => $id + 1,
                        'orders' => [...$state['orders'], $id => $order],
                    ]);
                }

                if ($msg instanceof GetOrder) {
                    $ctx->sender()?->tell($state['orders'][$msg->id] ?? null);
                    return BehaviorWithState::same();
                }

                if ($msg instanceof DeleteOrder) {
                    $orders = $state['orders'];
                    unset($orders[$msg->id]);
                    return BehaviorWithState::next(['nextId' => $state['nextId'], 'orders' => $orders]);
                }

                return BehaviorWithState::same();
            });
    }
}
```

- [ ] **Step 2: Routes file**

`examples/http-orders/src/routes.php`:
```php
<?php

declare(strict_types=1);

namespace Examples\HttpOrders;

use Examples\HttpOrders\Domain\CreateOrder;
use Examples\HttpOrders\Domain\DeleteOrder;
use Examples\HttpOrders\Domain\GetOrder;
use Monadial\Nexus\Http\Extract\IntNumber;
use Monadial\Nexus\Http\Routing\Route;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\concat;
use function Monadial\Nexus\Http\delete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\jsonBody;
use function Monadial\Nexus\Http\path;
use function Monadial\Nexus\Http\pathEnd;
use function Monadial\Nexus\Http\pathPrefix;
use function Monadial\Nexus\Http\post;
use function Monadial\Nexus\Http\useMiddlewares;

return static function (array $middlewares): Route {
    $orders = pathPrefix('orders', static fn() => concat(
        get(path(IntNumber::class, static fn(int $id) =>
            complete(static fn($ctx) => $ctx->ask('orders', new GetOrder($id))),
        )),
        post(pathEnd(static fn() =>
            jsonBody(\stdClass::class, static fn(\stdClass $cmd) =>
                complete(
                    static fn($ctx) => $ctx->ask('orders', new CreateOrder($cmd->sku, (int) $cmd->qty)),
                    201,
                ),
            ),
        )),
        delete(path(IntNumber::class, static fn(int $id) =>
            complete(static function ($ctx) use ($id) {
                $ctx->actorFor('orders')?->tell(new DeleteOrder($id));
                return ['deleted' => $id];
            }),
        )),
    ));

    return useMiddlewares($middlewares, static fn() => $orders);
};
```

- [ ] **Step 3: Server entry**

`examples/http-orders/bin/serve.php`:
```php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Examples\HttpOrders\Domain\OrderActor;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Middleware\LoggingMiddleware;
use Monadial\Nexus\Http\Middleware\RequestIdMiddleware;
use Monadial\Nexus\Http\Swoole\HttpServerBootstrap;
use Psr\Log\NullLogger;

$middlewares = [new RequestIdMiddleware(), new LoggingMiddleware(new NullLogger())];
$routesFactory = require __DIR__ . '/../src/routes.php';
$routes = $routesFactory($middlewares);

HttpServerBootstrap::dev($routes)
    ->host('0.0.0.0')
    ->port(8080)
    ->onSystemReady(static fn($system) => $system->spawn(
        Props::fromBehavior(OrderActor::create()),
        'orders',
    ))
    ->run();
```

- [ ] **Step 4: README**

`examples/http-orders/README.md`:
```markdown
# http-orders example

Demonstrates `nexus-http` Phase 1 DSL surface end-to-end via the T3 dev server.

## Run

```
docker compose exec php-swoole php examples/http-orders/bin/serve.php
```

## Try

```
curl -i -X POST -d '{"sku":"X","qty":3}' -H 'Content-Type: application/json' http://localhost:8080/orders
curl -i http://localhost:8080/orders/1
curl -i -X DELETE http://localhost:8080/orders/1
```
```

- [ ] **Step 5: Smoke test the example manually**

Run:
```bash
docker compose exec php-swoole php examples/http-orders/bin/serve.php &
sleep 1
curl -fsS -X POST -d '{"sku":"X","qty":3}' -H 'Content-Type: application/json' http://localhost:8080/orders
curl -fsS http://localhost:8080/orders/1
```
Expected: JSON responses for create + get.

- [ ] **Step 6: Commit**

```bash
git add examples/http-orders
git commit -m "example(http-orders): full Phase 1 DSL demo via T3 dev server"
```

---

## Task 30: Final coverage, deptrac, and lint pass

**Files:**
- Run-only.

- [ ] **Step 1: Coverage check on `nexus-http` and `nexus-http-testkit`**

Run: `docker compose exec php vendor/bin/phpunit --testsuite=unit --coverage-text`
Expected: ≥90% method coverage on `packages/nexus-http/src` and `packages/nexus-http-testkit/src`. If lower, add tests for the gaps reported by the coverage report (look at `clover.xml` or `--coverage-html`).

- [ ] **Step 2: Deptrac**

Run: `docker compose exec php vendor/bin/deptrac analyse`
Expected: 0 violations. Confirm `nexus-http` does not depend on Swoole/runtime layers, and `nexus-http-testkit` does not depend on Swoole.

- [ ] **Step 3: Psalm**

Run: `make psalm`
Expected: 0 errors.

- [ ] **Step 4: Code style**

Run: `make cs && make phpcs`
Expected: 0 violations. If any, run `make cs-fix && make phpcbf`.

- [ ] **Step 5: Run the full unit test suite**

Run: `make test-unit`
Expected: all tests pass.

- [ ] **Step 6: Run the integration test for the dev server**

Run: `docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-swoole --filter=HttpDevServerTest`
Expected: PASS.

- [ ] **Step 7: Commit any tweaks**

```bash
git add -A
git commit -m "chore(http): final lint and coverage tightening"
```

(Or skip this step if nothing needed adjustment.)

---

## Task 31: Open the PR

- [ ] **Step 1: Push the branch**

```bash
git push -u origin feat/nexus-http
```

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "feat(http): nexus-http Phase 1 — directive DSL + T3 dev server" --body "$(cat <<'EOF'
## Summary
- New `nexus-http` package: closure-nested directive DSL, type-safe extractors, content-negotiated marshalling, default error mapper, three baseline middlewares (BearerToken, RequestId, Logging) on top of PSR-7/15/17.
- New `nexus-http-testkit` package: pure-PHP route testing (Layer 1) plus actor-aware testing via `withSystem` + `StepRuntime` (Layer 2).
- New `nexus-http-swoole` package (Phase 1 surface only): `HttpServerBootstrap::dev()` — single-coroutine T3 dev server.
- Reference example: `examples/http-orders` running end-to-end via T3.

Implements Phase 1 of the design at `docs/plans/2026-04-27-nexus-http-design.md`. Multi-thread T1 mode and WebSockets land in Phases 2 and 3.

## Test plan
- [ ] `make test-unit` passes
- [ ] `docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-swoole --filter=HttpDevServerTest` passes
- [ ] Manual smoke: `php examples/http-orders/bin/serve.php` + curl POST/GET/DELETE
- [ ] Deptrac clean (`vendor/bin/deptrac analyse`)
- [ ] Psalm clean (`make psalm`)
- [ ] PHPCS / CS-fixer clean
EOF
)"
```

- [ ] **Step 3: Verify CI**

Wait for the GitHub Actions workflow to complete. Expected: all jobs green.

---

## Coverage by spec section (self-review map)

| Spec section | Implementing tasks |
|---|---|
| Architecture overview / package graph | 1, 2, 3 |
| Two-stage request lifecycle | 23, 28 |
| `Route` and the directive contract | 4 |
| Extractors | 15 |
| `RequestCtx` interface and impl | 6, 14 |
| `Marshaller`, `MarshallerRegistry` | 7, 8, 9 |
| Route compilation (DispatchTrie) | 23 |
| DSL examples | 11, 12, 13, 14, 16, 17, 18, 19, 20 |
| Two runtime modes (T3) | 28 |
| Hash-ring placement (T1, deferred) | Phase 2 |
| WebSocket model | Phase 3 |
| Error handling pipeline | 22 |
| Content negotiation | 9 |
| Body extraction safety | 18 |
| Logging integration | 21 |
| Shipped middlewares | 21 |
| Testing approach Layer 1 / Layer 2 | 24, 25 |
| Layer 3 integration test | 28 |
| Reference example | 29 |
| Cross-phase invariants 1–3, 4, 5, 7 | 1–3 (Deptrac), 22, 19, 28 |
| Done gate (90% coverage, example, deptrac) | 30 |
