# nexus-http core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `packages/nexus-http` — a PSR-15-compliant HTTP framework with actor injection, three lifecycle modes (pool-singleton, worker-local, per-request), sync + Future-returning handlers, fluent + attribute-discovered routing, compile-time pipeline, and PSR-16 route caching.

**Architecture:** Compile-time pipeline. `HttpApp` collects fluent declarations into immutable structures; `compile()` walks reflection once to build closure-based handlers AND compiles the full middleware stack into a single `RequestHandlerInterface` (`$compiledHandler`); the hot path runs no reflection and no per-request stack assembly. PSR-15 on the wire. FastRoute under the routing. `nyholm/psr7` for messages. PSR-16 (`Psr\SimpleCache\CacheInterface`) for route metadata caching — backend-agnostic (APCu, Redis, file system, …). Three lifecycle modes resolved at compile via `ResolvedActorTable`. Per-request actors lazy-spawned through `PerRequestActorScope` and `PoisonPill`-disposed in `finally`.

**Tech Stack:** PHP 8.5+, `nyholm/psr7` ^1.8, `nikic/fast-route` ^2.0, `psr/simple-cache` ^3.0, `nexus-actors/core` (existing), `nexus-actors/runtime` (existing — with a small extension), PSR-7/11/14/15/16/17. Tests in PHPUnit 13 via Docker (`make test-unit`). Lint via `make psalm phpcs cs`.

**Spec:** `docs/superpowers/specs/2026-06-10-nexus-http-core-design.md` (commit `27c7a928`).

**Reality vs spec discrepancies (resolved in this plan):**
- Namespace is `Monadial\Nexus\Http\` (spec wrote `Nexus\Http\`).
- `ActorRef::ask(object $message, Duration $timeout): Future<R>` — already returns `Future`. Sync form is `->ask(...)->await()`. No separate `askFuture`.
- `Future` API today: `await`, `map`, `flatMap`, `isResolved`, `cancel`, `onCancel`. Phase 1 of this plan adds `Future::all()`, `Future::resolved()`, `Future::failed()` to `nexus-runtime`. Other extensions (`race`, `recover`, `withTimeout`) deferred.

---

## Phase 0: Bootstrap the package

**Outcome:** Empty `packages/nexus-http` package present, autoloaded, with composer install passing and an empty PHPUnit testsuite that runs green.

**Files:**
- Create: `packages/nexus-http/composer.json`
- Create: `packages/nexus-http/README.md`
- Create: `packages/nexus-http/src/.gitkeep`
- Create: `packages/nexus-http/tests/Unit/.gitkeep`
- Modify: `composer.json` (root) — add `Monadial\Nexus\Http\` PSR-4 autoload + dev autoload
- Modify: `phpunit.xml` — add `packages/nexus-http/tests/Unit` to `unit` testsuite
- Modify: `deptrac.yaml` — add nexus-http layer with allowed deps (core, runtime)

- [ ] **Step 1:** Create the package skeleton directories

Run:
```bash
mkdir -p packages/nexus-http/src packages/nexus-http/tests/Unit
touch packages/nexus-http/src/.gitkeep packages/nexus-http/tests/Unit/.gitkeep
```

- [ ] **Step 2:** Write `packages/nexus-http/composer.json`

```json
{
    "name": "nexus-actors/http",
    "description": "Nexus HTTP framework — PSR-15 pipeline with actor injection.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "nexus-actors/core": "dev-main",
        "nexus-actors/runtime": "dev-main",
        "nikic/fast-route": "^2.0",
        "nyholm/psr7": "^1.8",
        "psr/container": "^2.0",
        "psr/event-dispatcher": "^1.0",
        "psr/http-factory": "^1.1",
        "psr/http-message": "^2.0",
        "psr/http-server-handler": "^1.0",
        "psr/http-server-middleware": "^1.0",
        "psr/log": "^3.0",
        "symfony/uid": "^8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
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

- [ ] **Step 3:** Write `packages/nexus-http/README.md` (minimal)

```markdown
# nexus-http

PSR-15 HTTP framework built on the Nexus actor system.

> See `docs/superpowers/specs/2026-06-10-nexus-http-core-design.md` for the design.

## Install

This package is published independently from the monorepo.

```bash
composer require nexus-actors/http
```

## Status

In active development.
```

- [ ] **Step 4:** Add the root composer.json autoload entries

Edit `composer.json` (root). Add to `autoload.psr-4` (alphabetical sort):

```json
"Monadial\\Nexus\\Http\\": "packages/nexus-http/src/",
```

Add to `autoload-dev.psr-4`:

```json
"Monadial\\Nexus\\Http\\Tests\\": "packages/nexus-http/tests/",
```

Also add the root composer.json `require` entries (alphabetical):

```json
"nikic/fast-route": "^2.0",
"nyholm/psr7": "^1.8",
"psr/http-factory": "^1.1",
"psr/http-message": "^2.0",
"psr/http-server-handler": "^1.0",
"psr/http-server-middleware": "^1.0"
```

- [ ] **Step 5:** Add `packages/nexus-http/tests/Unit` to `phpunit.xml` `unit` testsuite

Append inside the `<testsuite name="unit">` block (alphabetical sort within the block):

```xml
            <directory>packages/nexus-http/tests/Unit</directory>
```

- [ ] **Step 6:** Add nexus-http layer to `deptrac.yaml`

Edit `deptrac.yaml` — add a `Http` layer alongside the existing layers. Http depends on `Core`, `Runtime`, and `WorkerPool` (the last is read-only — only the `WorkerNode` type is referenced). Match the existing collectors style:

```yaml
  - name: Http
    collectors:
      - type: classLike
        regex: ^Monadial\\Nexus\\Http\\.*$
```

Add ruleset entry:
```yaml
  Http:
    - Core
    - Runtime
    - WorkerPool
```

- [ ] **Step 7:** Run composer install and verify zero errors

Run: `make install`
Expected: composer install succeeds; autoload regenerated.

- [ ] **Step 8:** Run the unit testsuite and verify it passes empty

Run: `make test-unit`
Expected: All existing tests pass. Nexus-http directory contributes 0 tests; testsuite still PASS.

- [ ] **Step 9:** Commit

```bash
git add packages/nexus-http composer.json composer.lock phpunit.xml deptrac.yaml
git -c commit.gpgsign=false commit -m "feat(http): bootstrap nexus-http package skeleton

Empty package wired into the monorepo autoload, PHPUnit, and deptrac.
Dependencies: nyholm/psr7, nikic/fast-route, PSR-7/11/14/15/17 contracts."
```

---

## Phase 1: Extend Future API (nexus-runtime)

**Outcome:** `Future::all()`, `Future::resolved()`, `Future::failed()` added to the existing `Future` class in `packages/nexus-runtime/src/Async/Future.php`, with unit tests in `nexus-runtime/tests/Unit`. Enables the spec's fan-out composition story.

**Files:**
- Modify: `packages/nexus-runtime/src/Async/Future.php`
- Create: `packages/nexus-runtime/tests/Unit/Async/FutureCombinatorsTest.php`

- [ ] **Step 1:** Write a failing test for `Future::resolved()`

Create `packages/nexus-runtime/tests/Unit/Async/FutureCombinatorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Tests\Unit\Async;

use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Exception\FutureException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(Future::class)]
final class FutureCombinatorsTest extends TestCase
{
    #[Test]
    public function resolved_returns_completed_future(): void
    {
        $value = new stdClass();

        $future = Future::resolved($value);

        self::assertTrue($future->isResolved());
        self::assertSame($value, $future->await());
    }
}
```

- [ ] **Step 2:** Run the test — expect failure

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-runtime/tests/Unit/Async/FutureCombinatorsTest.php --filter=resolved_returns_completed_future`
Expected: FAIL — `Future::resolved` does not exist.

- [ ] **Step 3:** Implement `Future::resolved()` in `Future.php`

Add to the class (after existing methods):

```php
    /**
     * Create a Future that is already completed with the given value.
     *
     * @template R2 of object
     * @param R2 $value
     * @return self<R2>
     */
    public static function resolved(object $value): self
    {
        $slot = new ImmediateFutureSlot();
        $slot->resolve($value);

        return new self($slot);
    }
```

You will need an `ImmediateFutureSlot` implementing `FutureSlot`. Create `packages/nexus-runtime/src/Async/ImmediateFutureSlot.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Async;

use Closure;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Override;

/**
 * @template R of object
 * @implements FutureSlot<R>
 */
final class ImmediateFutureSlot implements FutureSlot
{
    private ?object $value = null;
    private ?FutureException $error = null;
    private bool $resolved = false;

    /** @param R $value */
    #[Override]
    public function resolve(object $value): void
    {
        $this->value = $value;
        $this->resolved = true;
    }

    #[Override]
    public function fail(FutureException $e): void
    {
        $this->error = $e;
        $this->resolved = true;
    }

    #[Override]
    public function cancel(): void
    {
        // No-op for already resolved
    }

    #[Override]
    public function onCancel(Closure $callback): void
    {
        // No-op
    }

    #[Override]
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /** @return R */
    #[Override]
    public function await(): object
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        /** @var R */
        return $this->value;
    }
}
```

- [ ] **Step 4:** Run the test — expect PASS

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-runtime/tests/Unit/Async/FutureCombinatorsTest.php --filter=resolved_returns_completed_future`
Expected: PASS.

- [ ] **Step 5:** Add the failing test for `Future::failed()`

Append to `FutureCombinatorsTest.php`:

```php
    #[Test]
    public function failed_throws_on_await(): void
    {
        $error = new FutureException('boom');

        $future = Future::failed($error);

        self::assertTrue($future->isResolved());
        $this->expectException(FutureException::class);
        $this->expectExceptionMessage('boom');
        $future->await();
    }
```

- [ ] **Step 6:** Run — expect failure

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-runtime/tests/Unit/Async/FutureCombinatorsTest.php --filter=failed_throws_on_await`
Expected: FAIL — `Future::failed` does not exist.

- [ ] **Step 7:** Implement `Future::failed()`

Add to `Future.php`:

```php
    /**
     * Create a Future that is already failed.
     *
     * @return self<object>
     */
    public static function failed(FutureException $error): self
    {
        $slot = new ImmediateFutureSlot();
        $slot->fail($error);

        return new self($slot);
    }
```

- [ ] **Step 8:** Run — expect PASS

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-runtime/tests/Unit/Async/FutureCombinatorsTest.php --filter=failed_throws_on_await`
Expected: PASS.

- [ ] **Step 9:** Add the failing test for `Future::all()` with all resolved

Append:

```php
    #[Test]
    public function all_collects_resolved_values_by_key(): void
    {
        $a = new stdClass(); $a->name = 'a';
        $b = new stdClass(); $b->name = 'b';

        $combined = Future::all([
            'first'  => Future::resolved($a),
            'second' => Future::resolved($b),
        ]);

        $result = $combined->await();

        self::assertSame(['first' => $a, 'second' => $b], $result);
    }

    #[Test]
    public function all_propagates_first_failure(): void
    {
        $error = new FutureException('first failed');

        $combined = Future::all([
            Future::failed($error),
            Future::resolved(new stdClass()),
        ]);

        $this->expectException(FutureException::class);
        $this->expectExceptionMessage('first failed');
        $combined->await();
    }

    #[Test]
    public function all_returns_empty_array_for_empty_input(): void
    {
        $combined = Future::all([]);

        self::assertSame([], $combined->await());
    }
```

The `await()` returns an `object` per the signature, but `Future::all()` resolves to an array — we need to box. The convention: `Future::all()` returns `Future<ArrayContainer>` where `ArrayContainer` is a tiny readonly wrapper. Adjust the test:

Actually, since `await(): object` requires an object return, wrap the array. Update the tests to:

```php
    #[Test]
    public function all_collects_resolved_values_by_key(): void
    {
        $a = new stdClass(); $a->name = 'a';
        $b = new stdClass(); $b->name = 'b';

        $combined = Future::all([
            'first'  => Future::resolved($a),
            'second' => Future::resolved($b),
        ]);

        /** @var \Monadial\Nexus\Runtime\Async\FutureResult $result */
        $result = $combined->await();

        self::assertSame(['first' => $a, 'second' => $b], $result->values);
    }
```

And the empty-input test:

```php
    #[Test]
    public function all_returns_empty_result_for_empty_input(): void
    {
        $combined = Future::all([]);

        /** @var \Monadial\Nexus\Runtime\Async\FutureResult $result */
        $result = $combined->await();

        self::assertSame([], $result->values);
    }
```

- [ ] **Step 10:** Run — expect failure

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-runtime/tests/Unit/Async/FutureCombinatorsTest.php`
Expected: FAIL on the `all_*` tests — `Future::all` and `FutureResult` don't exist.

- [ ] **Step 11:** Create `packages/nexus-runtime/src/Async/FutureResult.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Async;

/**
 * @psalm-api
 *
 * Wraps the array result of Future::all() — Future<R> requires R to be an object.
 *
 * @template T
 */
final readonly class FutureResult
{
    /** @param array<array-key, T> $values */
    public function __construct(public array $values) {}
}
```

- [ ] **Step 12:** Implement `Future::all()` in `Future.php`

```php
    /**
     * Wait for all futures and collect their results, keyed identically to the input.
     * If any future fails, the combined future fails with the first failure encountered.
     *
     * @param array<array-key, Future<object>> $futures
     * @return Future<FutureResult>
     */
    public static function all(array $futures): self
    {
        if ($futures === []) {
            return self::resolved(new FutureResult([]));
        }

        /** @var FutureSlot<FutureResult> $combined */
        $combined = new LazyFutureSlot(static function () use ($futures): FutureResult {
            $values = [];
            foreach ($futures as $key => $future) {
                $values[$key] = $future->await();
            }

            return new FutureResult($values);
        });

        return new self($combined);
    }
```

- [ ] **Step 13:** Run — expect PASS

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-runtime/tests/Unit/Async/FutureCombinatorsTest.php`
Expected: All tests PASS.

- [ ] **Step 14:** Run psalm to ensure type safety

Run: `make psalm`
Expected: No new errors.

- [ ] **Step 15:** Commit

```bash
git add packages/nexus-runtime/src/Async/Future.php \
        packages/nexus-runtime/src/Async/ImmediateFutureSlot.php \
        packages/nexus-runtime/src/Async/FutureResult.php \
        packages/nexus-runtime/tests/Unit/Async/FutureCombinatorsTest.php
git -c commit.gpgsign=false commit -m "feat(runtime): add Future::all(), Future::resolved(), Future::failed()

Enables fan-out composition for nexus-http handlers. FutureResult wraps
the array return of all() because Future<R> requires R to be an object.
ImmediateFutureSlot backs the already-settled constructors."
```

---

## Phase 2: Response sugar — Response, JsonResponse

**Outcome:** `Response`, `JsonResponse` static factories in `nexus-http`, wrapping `nyholm/psr7`. Unit tests cover the status/header/body combinations the DSL examples use.

**Files:**
- Create: `packages/nexus-http/src/Response/Response.php`
- Create: `packages/nexus-http/src/Response/JsonResponse.php`
- Create: `packages/nexus-http/tests/Unit/Response/ResponseTest.php`
- Create: `packages/nexus-http/tests/Unit/Response/JsonResponseTest.php`

- [ ] **Step 1:** Write a failing test for `Response::ok()`

Create `packages/nexus-http/tests/Unit/Response/ResponseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Response;

use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    #[Test]
    public function ok_returns_empty_200(): void
    {
        $response = Response::ok();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function no_content_returns_204(): void
    {
        $response = Response::noContent();

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function created_returns_201_with_location_header(): void
    {
        $response = Response::created('/users/42');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/users/42', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function not_found_returns_404_with_message(): void
    {
        $response = Response::notFound('User not found');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('User not found', (string) $response->getBody());
    }

    #[Test]
    public function bad_request_returns_400(): void
    {
        $response = Response::badRequest('invalid');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function gateway_timeout_returns_504(): void
    {
        self::assertSame(504, Response::gatewayTimeout()->getStatusCode());
    }

    #[Test]
    public function service_unavailable_returns_503_with_retry_after_header(): void
    {
        $response = Response::serviceUnavailable(Duration::seconds(1));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function internal_server_error_returns_500(): void
    {
        self::assertSame(500, Response::internalServerError()->getStatusCode());
    }
}
```

- [ ] **Step 2:** Run — expect failure

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Response/ResponseTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3:** Implement `Response`

Create `packages/nexus-http/src/Response/Response.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Response;

use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

/**
 * @psalm-api
 *
 * Sugar for common ResponseInterface shapes. Returns nyholm/psr7 Response
 * instances so consumers can keep calling withStatus/withHeader/etc.
 */
final class Response
{
    public static function ok(): ResponseInterface
    {
        return new Psr7Response(200);
    }

    public static function noContent(): ResponseInterface
    {
        return new Psr7Response(204);
    }

    public static function created(?string $location = null): ResponseInterface
    {
        $headers = $location !== null ? ['Location' => $location] : [];

        return new Psr7Response(201, $headers);
    }

    public static function notFound(string $message = 'Not Found'): ResponseInterface
    {
        return new Psr7Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], $message);
    }

    public static function badRequest(string $message = 'Bad Request'): ResponseInterface
    {
        return new Psr7Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], $message);
    }

    public static function gatewayTimeout(): ResponseInterface
    {
        return new Psr7Response(504);
    }

    public static function serviceUnavailable(?Duration $retryAfter = null): ResponseInterface
    {
        $headers = [];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) ((int) $retryAfter->toSeconds());
        }

        return new Psr7Response(503, $headers);
    }

    public static function internalServerError(): ResponseInterface
    {
        return new Psr7Response(500);
    }
}
```

- [ ] **Step 4:** Run — expect PASS

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Response/ResponseTest.php`
Expected: PASS for all 8 tests.

- [ ] **Step 5:** Write a failing test for `JsonResponse`

Create `packages/nexus-http/tests/Unit/Response/JsonResponseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Response;

use Monadial\Nexus\Http\Response\JsonResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonResponse::class)]
final class JsonResponseTest extends TestCase
{
    #[Test]
    public function ok_serializes_array_as_json(): void
    {
        $response = JsonResponse::ok(['name' => 'Tomas', 'count' => 3]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"Tomas","count":3}', (string) $response->getBody());
    }

    #[Test]
    public function ok_serializes_scalar(): void
    {
        $response = JsonResponse::ok(42);

        self::assertSame('42', (string) $response->getBody());
    }

    #[Test]
    public function created_returns_201_with_location_and_body(): void
    {
        $response = JsonResponse::created(['id' => 7], '/users/7');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/users/7', $response->getHeaderLine('Location'));
        self::assertSame('{"id":7}', (string) $response->getBody());
    }
}
```

- [ ] **Step 6:** Run — expect failure

Expected: FAIL — class missing.

- [ ] **Step 7:** Implement `JsonResponse`

Create `packages/nexus-http/src/Response/JsonResponse.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Response;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

/**
 * @psalm-api
 *
 * JSON response sugar. JSON_UNESCAPED_SLASHES is the default since slashes
 * appear constantly in URLs and look ugly when escaped.
 */
final class JsonResponse
{
    public const int DEFAULT_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function ok(mixed $data, int $flags = self::DEFAULT_FLAGS): ResponseInterface
    {
        return self::build(200, $data, $flags);
    }

    public static function created(mixed $data, ?string $location = null, int $flags = self::DEFAULT_FLAGS): ResponseInterface
    {
        $response = self::build(201, $data, $flags);
        if ($location !== null) {
            $response = $response->withHeader('Location', $location);
        }

        return $response;
    }

    private static function build(int $status, mixed $data, int $flags): ResponseInterface
    {
        $body = json_encode($data, $flags | JSON_THROW_ON_ERROR);

        return new Psr7Response(
            $status,
            ['Content-Type' => 'application/json'],
            $body,
        );
    }
}
```

- [ ] **Step 8:** Run — expect PASS

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Response/JsonResponseTest.php`
Expected: PASS for all 3 tests.

- [ ] **Step 9:** Run psalm + phpcs to ensure compliance

Run: `make psalm && make phpcs`
Expected: No errors.

- [ ] **Step 10:** Commit

```bash
git add packages/nexus-http/src/Response/ packages/nexus-http/tests/Unit/Response/
git -c commit.gpgsign=false commit -m "feat(http): add Response and JsonResponse sugar

Static factories over nyholm/psr7 for common status codes (ok, created,
notFound, badRequest, gatewayTimeout, serviceUnavailable). JsonResponse
defaults to UNESCAPED_SLASHES + UNICODE for cleaner output."
```

---

## Phase 3: Streaming responses

**Outcome:** `StreamingResponse::fromGenerator()`, `::ndjson()`, `::sse()`, `::file()` available. Backing `StreamInterface` pulls chunks from the iterator on each `read()`, enabling true server-side chunked writes (server adapters MUST honour this contract).

**Files:**
- Create: `packages/nexus-http/src/Response/StreamingResponse.php`
- Create: `packages/nexus-http/src/Response/IteratorStream.php`
- Create: `packages/nexus-http/tests/Unit/Response/StreamingResponseTest.php`
- Create: `packages/nexus-http/tests/Unit/Response/IteratorStreamTest.php`

- [ ] **Step 1:** Write a failing test for `IteratorStream`

Create `packages/nexus-http/tests/Unit/Response/IteratorStreamTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Response;

use Monadial\Nexus\Http\Response\IteratorStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IteratorStream::class)]
final class IteratorStreamTest extends TestCase
{
    #[Test]
    public function read_pulls_one_chunk_per_call(): void
    {
        $chunks = (static function () {
            yield 'one';
            yield 'two';
            yield 'three';
        })();

        $stream = new IteratorStream($chunks);

        self::assertSame('one', $stream->read(1024));
        self::assertSame('two', $stream->read(1024));
        self::assertSame('three', $stream->read(1024));
    }

    #[Test]
    public function read_returns_empty_when_iterator_exhausted(): void
    {
        $chunks = (static function () { yield 'only'; })();

        $stream = new IteratorStream($chunks);
        $stream->read(1024);

        self::assertSame('', $stream->read(1024));
        self::assertTrue($stream->eof());
    }

    #[Test]
    public function get_contents_concatenates_remaining_chunks(): void
    {
        $chunks = (static function () {
            yield 'hello ';
            yield 'world';
        })();

        $stream = new IteratorStream($chunks);

        self::assertSame('hello world', $stream->getContents());
    }
}
```

- [ ] **Step 2:** Run — expect failure

Expected: FAIL — class missing.

- [ ] **Step 3:** Implement `IteratorStream`

Create `packages/nexus-http/src/Response/IteratorStream.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Response;

use Iterator;
use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * @psalm-api
 *
 * PSR-7 StreamInterface backed by an Iterator. Each read() pulls one yielded
 * chunk. Designed for server adapters that want to flush per chunk
 * (Swoole's Response::write loop). Non-seekable; read-only.
 */
final class IteratorStream implements StreamInterface
{
    private bool $eof = false;

    public function __construct(private readonly Iterator $iterator) {}

    #[Override]
    public function read(int $length): string
    {
        if ($this->eof) {
            return '';
        }

        if (!$this->iterator->valid()) {
            $this->eof = true;
            return '';
        }

        $chunk = (string) $this->iterator->current();
        $this->iterator->next();

        if (!$this->iterator->valid()) {
            $this->eof = true;
        }

        return $chunk;
    }

    #[Override]
    public function eof(): bool
    {
        return $this->eof;
    }

    #[Override]
    public function getContents(): string
    {
        $out = '';
        while (!$this->eof) {
            $out .= $this->read(8192);
        }

        return $out;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->getContents();
    }

    #[Override]
    public function close(): void {}

    #[Override]
    public function detach() { return null; }

    #[Override]
    public function getSize(): ?int { return null; }

    #[Override]
    public function tell(): int { throw new RuntimeException('IteratorStream is not seekable'); }

    #[Override]
    public function isSeekable(): bool { return false; }

    #[Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('IteratorStream is not seekable');
    }

    #[Override]
    public function rewind(): void { throw new RuntimeException('IteratorStream is not seekable'); }

    #[Override]
    public function isWritable(): bool { return false; }

    #[Override]
    public function write(string $string): int { throw new RuntimeException('IteratorStream is read-only'); }

    #[Override]
    public function isReadable(): bool { return true; }

    #[Override]
    public function getMetadata(?string $key = null) { return $key === null ? [] : null; }
}
```

- [ ] **Step 4:** Run — expect PASS

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Response/IteratorStreamTest.php`
Expected: PASS.

- [ ] **Step 5:** Write failing tests for `StreamingResponse`

Create `packages/nexus-http/tests/Unit/Response/StreamingResponseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Response;

use Monadial\Nexus\Http\Response\StreamingResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamingResponse::class)]
final class StreamingResponseTest extends TestCase
{
    #[Test]
    public function from_generator_streams_chunks(): void
    {
        $gen = (static function () {
            yield 'a';
            yield 'b';
        })();

        $response = StreamingResponse::fromGenerator($gen, 200, ['X-Custom' => 'yes']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Custom'));
        self::assertSame('ab', (string) $response->getBody());
    }

    #[Test]
    public function ndjson_serializes_each_item_with_newline(): void
    {
        $response = StreamingResponse::ndjson([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        self::assertSame('application/x-ndjson', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            "{\"id\":1,\"name\":\"a\"}\n{\"id\":2,\"name\":\"b\"}\n",
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function sse_formats_events_in_sse_protocol(): void
    {
        $response = StreamingResponse::sse([
            ['event' => 'message', 'data' => 'hello'],
            ['data' => 'world'],
        ]);

        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            "event: message\ndata: hello\n\ndata: world\n\n",
            (string) $response->getBody(),
        );
    }
}
```

- [ ] **Step 6:** Run — expect failure

Expected: FAIL.

- [ ] **Step 7:** Implement `StreamingResponse`

Create `packages/nexus-http/src/Response/StreamingResponse.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Response;

use ArrayIterator;
use Closure;
use Generator;
use Iterator;
use IteratorAggregate;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * @psalm-api
 *
 * Static factories for streaming responses. The body is an IteratorStream that
 * pulls chunks per read(). Server adapters MUST honour read+flush per chunk
 * for SSE/NDJSON to deliver incrementally.
 */
final class StreamingResponse
{
    public static function fromGenerator(Generator $chunks, int $status = 200, array $headers = []): ResponseInterface
    {
        return (new Psr7Response($status, $headers))
            ->withBody(new IteratorStream($chunks));
    }

    /**
     * Each item becomes one newline-delimited JSON object.
     *
     * @param iterable<mixed> $items
     * @param Closure(mixed): string|null $encoder Custom encoder. Defaults to json_encode.
     */
    public static function ndjson(iterable $items, ?Closure $encoder = null): ResponseInterface
    {
        $encoder ??= static fn(mixed $item): string => json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $iterator = self::toIterator($items);
        $chunks = (static function () use ($iterator, $encoder): Generator {
            foreach ($iterator as $item) {
                yield $encoder($item) . "\n";
            }
        })();

        return (new Psr7Response(200, ['Content-Type' => 'application/x-ndjson']))
            ->withBody(new IteratorStream($chunks));
    }

    /**
     * Server-Sent Events. Each event is an array with keys: id?, event?, data, retry?.
     *
     * @param iterable<array{id?: string, event?: string, data: string, retry?: int}> $events
     */
    public static function sse(iterable $events): ResponseInterface
    {
        $iterator = self::toIterator($events);
        $chunks = (static function () use ($iterator): Generator {
            foreach ($iterator as $event) {
                $out = '';
                if (isset($event['id']))    { $out .= "id: {$event['id']}\n"; }
                if (isset($event['event'])) { $out .= "event: {$event['event']}\n"; }
                if (isset($event['retry'])) { $out .= "retry: {$event['retry']}\n"; }
                $out .= "data: {$event['data']}\n\n";
                yield $out;
            }
        })();

        return (new Psr7Response(200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]))->withBody(new IteratorStream($chunks));
    }

    public static function file(string $path, ?string $contentType = null): ResponseInterface
    {
        if (!is_readable($path)) {
            throw new RuntimeException("File not readable: {$path}");
        }

        $headers = ['Content-Length' => (string) filesize($path)];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        return new Psr7Response(200, $headers, Stream::create(fopen($path, 'rb')));
    }

    /** @param iterable<mixed> $items */
    private static function toIterator(iterable $items): Iterator
    {
        if ($items instanceof Iterator) {
            return $items;
        }
        if ($items instanceof IteratorAggregate) {
            return $items->getIterator();
        }
        return new ArrayIterator(is_array($items) ? $items : iterator_to_array($items));
    }
}
```

- [ ] **Step 8:** Run — expect PASS

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Response/StreamingResponseTest.php`
Expected: PASS.

- [ ] **Step 9:** Run psalm + phpcs

Run: `make psalm && make phpcs`
Expected: clean.

- [ ] **Step 10:** Commit

```bash
git add packages/nexus-http/src/Response/StreamingResponse.php \
        packages/nexus-http/src/Response/IteratorStream.php \
        packages/nexus-http/tests/Unit/Response/StreamingResponseTest.php \
        packages/nexus-http/tests/Unit/Response/IteratorStreamTest.php
git -c commit.gpgsign=false commit -m "feat(http): add StreamingResponse with ndjson + sse + generator + file

IteratorStream is a PSR-7 StreamInterface that pulls chunks per read()
from any Iterator. Server adapters flush per read() chunk to make SSE
and NDJSON deliver incrementally."
```

---

## Phase 4: Routing primitives — Route, RouteCollection, Dispatcher

**Outcome:** Immutable `Route` value object, mutable `RouteCollection` with duplicate detection, `Dispatcher` wrapping FastRoute. `#[Route]` attribute defined. No fluent builder yet (that comes with `HttpApp` in phase 10).

**Files:**
- Create: `packages/nexus-http/src/Routing/Route.php`
- Create: `packages/nexus-http/src/Routing/RouteCollection.php`
- Create: `packages/nexus-http/src/Routing/Dispatcher.php`
- Create: `packages/nexus-http/src/Routing/DispatchResult.php`
- Create: `packages/nexus-http/src/Routing/Attribute/Route.php`
- Create: `packages/nexus-http/tests/Unit/Routing/RouteTest.php`
- Create: `packages/nexus-http/tests/Unit/Routing/RouteCollectionTest.php`
- Create: `packages/nexus-http/tests/Unit/Routing/DispatcherTest.php`

- [ ] **Step 1:** Write a failing test for `Route` immutability

Create `packages/nexus-http/tests/Unit/Routing/RouteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Monadial\Nexus\Http\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    #[Test]
    public function exposes_constructor_arguments_as_public_readonly(): void
    {
        $route = new Route('GET', '/users/{id}', 'App\\GetUser', ['Auth'], 'users.show');

        self::assertSame('GET', $route->method);
        self::assertSame('/users/{id}', $route->path);
        self::assertSame('App\\GetUser', $route->handler);
        self::assertSame(['Auth'], $route->middleware);
        self::assertSame('users.show', $route->name);
    }

    #[Test]
    public function with_added_middleware_returns_new_instance_preserving_order(): void
    {
        $original = new Route('POST', '/orders', 'App\\CreateOrder', ['Auth'], null);

        $extended = $original->withAddedMiddleware(['Idempotency']);

        self::assertSame(['Auth'], $original->middleware);
        self::assertSame(['Auth', 'Idempotency'], $extended->middleware);
    }
}
```

- [ ] **Step 2:** Run — expect failure

Expected: FAIL — class missing.

- [ ] **Step 3:** Implement `Route`

Create `packages/nexus-http/src/Routing/Route.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use Closure;

/**
 * @psalm-api
 *
 * Immutable route definition. Produced by the fluent builder and by
 * attribute discovery; consumed by Dispatcher and HandlerResolver.
 *
 * The `handler` is either a class name string, a 'Class::method' string,
 * or a Closure. HandlerResolver compiles each to a ResolvedHandler.
 */
final readonly class Route
{
    /** @param list<string> $middleware Fully-qualified middleware class names. */
    public function __construct(
        public string $method,
        public string $path,
        public string|Closure $handler,
        public array $middleware,
        public ?string $name,
    ) {}

    /** @param list<string> $middleware */
    public function withAddedMiddleware(array $middleware): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            [...$this->middleware, ...$middleware],
            $this->name,
        );
    }

    public function withPrefixedPath(string $prefix): self
    {
        $combined = rtrim($prefix, '/') . '/' . ltrim($this->path, '/');
        $combined = $combined === '' ? '/' : $combined;

        return new self($this->method, $combined, $this->handler, $this->middleware, $this->name);
    }
}
```

- [ ] **Step 4:** Run — expect PASS

Expected: PASS.

- [ ] **Step 5:** Write a failing test for `RouteCollection`

Create `packages/nexus-http/tests/Unit/Routing/RouteCollectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Monadial\Nexus\Http\Exception\DuplicateRouteNameException;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Http\Routing\RouteCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteCollection::class)]
final class RouteCollectionTest extends TestCase
{
    #[Test]
    public function add_appends_routes_in_order(): void
    {
        $collection = new RouteCollection();
        $a = new Route('GET', '/a', 'A', [], null);
        $b = new Route('GET', '/b', 'B', [], null);

        $collection->add($a);
        $collection->add($b);

        self::assertSame([$a, $b], $collection->all());
    }

    #[Test]
    public function find_by_name_returns_matching_route(): void
    {
        $collection = new RouteCollection();
        $route = new Route('GET', '/users/{id}', 'App\\GetUser', [], 'users.show');
        $collection->add($route);

        self::assertSame($route, $collection->findByName('users.show'));
    }

    #[Test]
    public function find_by_name_returns_null_when_missing(): void
    {
        $collection = new RouteCollection();

        self::assertNull($collection->findByName('nope'));
    }

    #[Test]
    public function add_throws_on_duplicate_name(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('GET', '/a', 'A', [], 'shared'));

        $this->expectException(DuplicateRouteNameException::class);
        $collection->add(new Route('POST', '/b', 'B', [], 'shared'));
    }
}
```

- [ ] **Step 6:** Run — expect failure

Expected: FAIL.

- [ ] **Step 7:** Create the supporting exception

Create `packages/nexus-http/src/Exception/DuplicateRouteNameException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown at route registration time when two routes share the same name.
 * Registration order matters; the second registration triggers this.
 */
final class DuplicateRouteNameException extends NexusException
{
    public function __construct(string $name)
    {
        parent::__construct("Route name '{$name}' is already registered.");
    }
}
```

- [ ] **Step 8:** Implement `RouteCollection`

Create `packages/nexus-http/src/Routing/RouteCollection.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use Monadial\Nexus\Http\Exception\DuplicateRouteNameException;

/**
 * @psalm-api
 *
 * Mutable collection used during boot. Frozen at HttpApp::compile().
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    public function add(Route $route): void
    {
        if ($route->name !== null) {
            if (isset($this->named[$route->name])) {
                throw new DuplicateRouteNameException($route->name);
            }
            $this->named[$route->name] = $route;
        }

        $this->routes[] = $route;
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }

    public function findByName(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }
}
```

- [ ] **Step 9:** Run — expect PASS

Expected: PASS.

- [ ] **Step 10:** Write a failing test for `Dispatcher`

Create `packages/nexus-http/tests/Unit/Routing/DispatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Monadial\Nexus\Http\Routing\DispatchResult;
use Monadial\Nexus\Http\Routing\Dispatcher;
use Monadial\Nexus\Http\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Dispatcher::class)]
#[CoversClass(DispatchResult::class)]
final class DispatcherTest extends TestCase
{
    #[Test]
    public function dispatch_returns_matched_route_with_path_params(): void
    {
        $route = new Route('GET', '/users/{id}', 'App\\GetUser', [], null);
        $dispatcher = Dispatcher::build([$route]);

        $result = $dispatcher->dispatch('GET', '/users/42');

        self::assertSame(DispatchResult::FOUND, $result->status);
        self::assertSame($route, $result->route);
        self::assertSame(['id' => '42'], $result->pathParams);
    }

    #[Test]
    public function dispatch_returns_not_found(): void
    {
        $dispatcher = Dispatcher::build([new Route('GET', '/users', 'A', [], null)]);

        $result = $dispatcher->dispatch('GET', '/orders');

        self::assertSame(DispatchResult::NOT_FOUND, $result->status);
        self::assertNull($result->route);
    }

    #[Test]
    public function dispatch_returns_method_not_allowed_with_allow_header(): void
    {
        $dispatcher = Dispatcher::build([new Route('GET', '/users', 'A', [], null)]);

        $result = $dispatcher->dispatch('POST', '/users');

        self::assertSame(DispatchResult::METHOD_NOT_ALLOWED, $result->status);
        self::assertSame(['GET'], $result->allowedMethods);
    }
}
```

- [ ] **Step 11:** Run — expect failure

Expected: FAIL.

- [ ] **Step 12:** Implement `DispatchResult`

Create `packages/nexus-http/src/Routing/DispatchResult.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

/**
 * @psalm-api
 */
final readonly class DispatchResult
{
    public const int FOUND = 1;
    public const int NOT_FOUND = 2;
    public const int METHOD_NOT_ALLOWED = 3;

    /**
     * @param self::FOUND|self::NOT_FOUND|self::METHOD_NOT_ALLOWED $status
     * @param array<string, string> $pathParams
     * @param list<string> $allowedMethods
     */
    public function __construct(
        public int $status,
        public ?Route $route,
        public array $pathParams,
        public array $allowedMethods,
    ) {}

    /** @param array<string, string> $params */
    public static function found(Route $route, array $params): self
    {
        return new self(self::FOUND, $route, $params, []);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null, [], []);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(self::METHOD_NOT_ALLOWED, null, [], $allowed);
    }
}
```

- [ ] **Step 13:** Implement `Dispatcher`

Create `packages/nexus-http/src/Routing/Dispatcher.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use FastRoute\Dispatcher as FastRouteDispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * @psalm-api
 *
 * Thin wrapper over FastRoute. Stores Route objects in a side table so the
 * matched id can be resolved back to the Route. (FastRoute returns the
 * handler payload — we pass the route id and look up by id.)
 */
final class Dispatcher
{
    /**
     * @param array<int, Route> $routes
     */
    private function __construct(
        private readonly FastRouteDispatcher $delegate,
        private readonly array $routes,
    ) {}

    /** @param list<Route> $routes */
    public static function build(array $routes): self
    {
        $byId = [];
        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($routes, &$byId): void {
            foreach ($routes as $id => $route) {
                $byId[$id] = $route;
                $r->addRoute($route->method, $route->path, $id);
            }
        });

        return new self($dispatcher, $byId);
    }

    public function dispatch(string $method, string $path): DispatchResult
    {
        $info = $this->delegate->dispatch($method, $path);

        return match ($info[0]) {
            FastRouteDispatcher::FOUND              => DispatchResult::found($this->routes[$info[1]], $info[2]),
            FastRouteDispatcher::METHOD_NOT_ALLOWED => DispatchResult::methodNotAllowed($info[1]),
            default                                 => DispatchResult::notFound(),
        };
    }
}
```

- [ ] **Step 14:** Run — expect PASS

Expected: PASS.

- [ ] **Step 15:** Implement `#[Route]` attribute

Create `packages/nexus-http/src/Routing/Attribute/Route.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Declare an HTTP route on an action class. Repeatable: one class may
 * declare multiple endpoints.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /** @param list<string> $middleware */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $name = null,
        public array $middleware = [],
    ) {}
}
```

- [ ] **Step 16:** Quick attribute reflection test

Create `packages/nexus-http/tests/Unit/Routing/Attribute/RouteAttributeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing\Attribute;

use Monadial\Nexus\Http\Routing\Attribute\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Route('GET', '/users/{id}', name: 'users.show', middleware: ['Auth'])]
#[Route('GET', '/users', name: 'users.index')]
final class _AttributeFixture {}

#[CoversClass(Route::class)]
final class RouteAttributeTest extends TestCase
{
    #[Test]
    public function attribute_is_repeatable_and_carries_arguments(): void
    {
        $attrs = (new ReflectionClass(_AttributeFixture::class))->getAttributes(Route::class);

        self::assertCount(2, $attrs);
        $first = $attrs[0]->newInstance();
        self::assertSame('GET', $first->method);
        self::assertSame('/users/{id}', $first->path);
        self::assertSame('users.show', $first->name);
        self::assertSame(['Auth'], $first->middleware);
    }
}
```

- [ ] **Step 17:** Run all phase-4 tests

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Routing/`
Expected: All tests PASS.

- [ ] **Step 18:** Run lint

Run: `make psalm && make phpcs`
Expected: clean.

- [ ] **Step 19:** Commit

```bash
git add packages/nexus-http/src/Routing/ \
        packages/nexus-http/src/Exception/DuplicateRouteNameException.php \
        packages/nexus-http/tests/Unit/Routing/
git -c commit.gpgsign=false commit -m "feat(http): add routing primitives — Route, RouteCollection, Dispatcher

Immutable Route value object with withAddedMiddleware/withPrefixedPath
combinators. RouteCollection enforces unique names at registration.
Dispatcher wraps FastRoute and returns a typed DispatchResult.
#[Route] attribute is repeatable for multi-endpoint action classes."
```

---

## Phase 5: Actor modes + registry

**Outcome:** `ActorMode` enum and runtime types (`ActorRegistry`, `ActorRegistrationEntry`) in `Actor\`. The fluent setter `ActorRegistration` lives in the `Dsl\` namespace alongside the rest of the DSL surface. Validation rules deferred until phase 6 when `ResolvedActorTable` exists.

**Files:**
- Create: `packages/nexus-http/src/Actor/ActorMode.php`
- Create: `packages/nexus-http/src/Dsl/ActorRegistration.php`
- Create: `packages/nexus-http/src/Actor/ActorRegistry.php`
- Create: `packages/nexus-http/src/Actor/ActorRegistrationEntry.php`
- Create: `packages/nexus-http/src/Exception/DuplicateActorNameException.php`
- Create: `packages/nexus-http/tests/Unit/Actor/ActorRegistryTest.php`

- [ ] **Step 1:** Implement `ActorMode` enum

Create `packages/nexus-http/src/Actor/ActorMode.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

/**
 * @psalm-api
 *
 * Lifecycle mode for an injected actor. Set once at registration.
 */
enum ActorMode
{
    /** One actor for the entire worker pool, addressed via hash ring. */
    case PoolSingleton;

    /** One instance per worker thread. */
    case WorkerLocal;

    /** Spawned per HTTP request, stopped at request end. */
    case PerRequest;
}
```

- [ ] **Step 2:** Implement `ActorRegistrationEntry` (the frozen record)

Create `packages/nexus-http/src/Actor/ActorRegistrationEntry.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;

/**
 * @psalm-api
 *
 * Frozen registration record. Consumed by ResolvedActorTable at compile time.
 */
final readonly class ActorRegistrationEntry
{
    public function __construct(
        public string $name,
        public Props $props,
        public ActorMode $mode,
        public ?SupervisionStrategy $supervision,
        public ?MailboxConfig $mailbox,
    ) {}
}
```

- [ ] **Step 3:** Implement `Dsl\ActorRegistration` (the fluent setter)

Create `packages/nexus-http/src/Dsl/ActorRegistration.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistry;

/**
 * @psalm-api
 *
 * Fluent setter returned by HttpApp::actor() / perRequestActor(). Mutates
 * the entry in the registry. The terminal accessors freeze into
 * ActorRegistrationEntry at compile time.
 */
final class ActorRegistration
{
    private ActorMode $mode;
    private ?SupervisionStrategy $supervision = null;
    private ?MailboxConfig $mailbox = null;

    public function __construct(
        private readonly string $name,
        private readonly ActorRegistry $registry,
        ActorMode $initialMode,
    ) {
        $this->mode = $initialMode;
    }

    public function mode(ActorMode $mode): self
    {
        $this->mode = $mode;
        $this->registry->update($this);

        return $this;
    }

    public function poolSingleton(): self
    {
        return $this->mode(ActorMode::PoolSingleton);
    }

    public function workerLocal(): self
    {
        return $this->mode(ActorMode::WorkerLocal);
    }

    public function withSupervision(SupervisionStrategy $strategy): self
    {
        $this->supervision = $strategy;
        $this->registry->update($this);

        return $this;
    }

    public function withMailbox(MailboxConfig $config): self
    {
        $this->mailbox = $config;
        $this->registry->update($this);

        return $this;
    }

    public function name(): string { return $this->name; }
    public function currentMode(): ActorMode { return $this->mode; }
    public function currentSupervision(): ?SupervisionStrategy { return $this->supervision; }
    public function currentMailbox(): ?MailboxConfig { return $this->mailbox; }
}
```

- [ ] **Step 4:** Implement `ActorRegistry`

Create `packages/nexus-http/src/Actor/ActorRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Dsl\ActorRegistration;
use Monadial\Nexus\Http\Exception\DuplicateActorNameException;

/**
 * @psalm-api
 *
 * Mutable boot-time table of actor declarations. Frozen at HttpApp::compile()
 * into a list of ActorRegistrationEntry that feed ResolvedActorTable.
 */
final class ActorRegistry
{
    /** @var array<string, array{props: Props, registration: ActorRegistration}> */
    private array $entries = [];

    public function register(string $name, Props $props, ActorMode $initialMode): ActorRegistration
    {
        if (isset($this->entries[$name])) {
            throw new DuplicateActorNameException($name);
        }

        $registration = new ActorRegistration($name, $this, $initialMode);
        $this->entries[$name] = ['props' => $props, 'registration' => $registration];

        return $registration;
    }

    /** Called by ActorRegistration on every mutation — no-op besides being part of the contract. */
    public function update(ActorRegistration $registration): void {}

    /** @return list<ActorRegistrationEntry> */
    public function freeze(): array
    {
        $entries = [];
        foreach ($this->entries as $name => $bundle) {
            $reg = $bundle['registration'];
            $entries[] = new ActorRegistrationEntry(
                $name,
                $bundle['props'],
                $reg->currentMode(),
                $reg->currentSupervision(),
                $reg->currentMailbox(),
            );
        }

        return $entries;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }
}
```

- [ ] **Step 5:** Create `DuplicateActorNameException`

Create `packages/nexus-http/src/Exception/DuplicateActorNameException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class DuplicateActorNameException extends NexusException
{
    public function __construct(string $name)
    {
        parent::__construct("Actor '{$name}' is already registered with HttpApp.");
    }
}
```

- [ ] **Step 6:** Write tests for `ActorRegistry` + `ActorRegistration`

Create `packages/nexus-http/tests/Unit/Actor/ActorRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistry;
use Monadial\Nexus\Http\Exception\DuplicateActorNameException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorRegistry::class)]
final class ActorRegistryTest extends TestCase
{
    #[Test]
    public function register_returns_registration_with_default_mode(): void
    {
        $registry = new ActorRegistry();
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));

        $registration = $registry->register('store', $props, ActorMode::WorkerLocal);

        self::assertSame('store', $registration->name());
        self::assertSame(ActorMode::WorkerLocal, $registration->currentMode());
    }

    #[Test]
    public function register_throws_on_duplicate_name(): void
    {
        $registry = new ActorRegistry();
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));

        $registry->register('store', $props, ActorMode::WorkerLocal);
        $this->expectException(DuplicateActorNameException::class);
        $registry->register('store', $props, ActorMode::WorkerLocal);
    }

    #[Test]
    public function freeze_captures_the_latest_mutation_state(): void
    {
        $registry = new ActorRegistry();
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
        $registry->register('store', $props, ActorMode::WorkerLocal)->poolSingleton();

        $entries = $registry->freeze();

        self::assertCount(1, $entries);
        self::assertSame(ActorMode::PoolSingleton, $entries[0]->mode);
    }
}
```

- [ ] **Step 7:** Run tests

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Actor/ActorRegistryTest.php`
Expected: PASS.

- [ ] **Step 8:** Run lint

Run: `make psalm && make phpcs`
Expected: clean.

- [ ] **Step 9:** Commit

```bash
git add packages/nexus-http/src/Actor/ \
        packages/nexus-http/src/Dsl/ActorRegistration.php \
        packages/nexus-http/src/Exception/DuplicateActorNameException.php \
        packages/nexus-http/tests/Unit/Actor/ActorRegistryTest.php
git -c commit.gpgsign=false commit -m "feat(http): add ActorMode enum, ActorRegistry, Dsl\\ActorRegistration

Boot-time actor registration with fluent mode/supervision/mailbox setters.
Three lifecycle modes (PoolSingleton, WorkerLocal, PerRequest). Registry
freezes to a list of ActorRegistrationEntry for the compiler. The fluent
ActorRegistration setter lives in the Dsl\\ namespace alongside the rest
of the user-facing DSL surface."
```

---

## Phase 6: Resolved actor table + per-request scope

**Outcome:** `ResolvedActorTable` built from frozen registry entries at compile time. Worker-local actors spawned during build. Pool-singleton actors resolved via an injected `WorkerNode` if present. `PerRequestActorScope` holds lazy spawn + idempotent dispose. A `requestScopeGuardian` actor is spawned by the framework to parent per-request actors.

**Files:**
- Create: `packages/nexus-http/src/Actor/ResolvedActorTable.php`
- Create: `packages/nexus-http/src/Actor/PerRequestActorScope.php`
- Create: `packages/nexus-http/src/Actor/RequestScopeGuardian.php`
- Create: `packages/nexus-http/src/Exception/UnknownActorException.php`
- Create: `packages/nexus-http/src/Exception/PoolSingletonRequiresWorkerNodeException.php`
- Create: `packages/nexus-http/src/Exception/PerRequestActorInConstructorException.php`
- Create: `packages/nexus-http/tests/Unit/Actor/ResolvedActorTableTest.php`
- Create: `packages/nexus-http/tests/Unit/Actor/PerRequestActorScopeTest.php`

- [ ] **Step 1:** Create the supporting exceptions

`packages/nexus-http/src/Exception/UnknownActorException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class UnknownActorException extends NexusException
{
    public function __construct(string $name)
    {
        parent::__construct("No actor registered with name '{$name}'.");
    }
}
```

`packages/nexus-http/src/Exception/PoolSingletonRequiresWorkerNodeException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class PoolSingletonRequiresWorkerNodeException extends NexusException
{
    /** @param list<string> $names */
    public function __construct(array $names)
    {
        $list = implode(', ', $names);
        parent::__construct(
            "Pool-singleton actor(s) [{$list}] declared, but no WorkerNode "
            . "was attached to HttpApp. Wire nexus-worker-pool-swoole, or "
            . "change the mode to workerLocal()."
        );
    }
}
```

`packages/nexus-http/src/Exception/PerRequestActorInConstructorException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class PerRequestActorInConstructorException extends NexusException
{
    public function __construct(string $class, string $param, string $actorName)
    {
        parent::__construct(
            "{$class}::__construct(\${$param}) injects per-request actor "
            . "'{$actorName}'. Per-request actors can only be injected into "
            . "the handler/middleware invocation method, not the constructor."
        );
    }
}
```

- [ ] **Step 2:** Implement `RequestScopeGuardian`

The guardian is an actor whose only job is to be the parent of per-request actors so supervision is correctly scoped. It uses `Behavior::receive` and discards messages (real per-request actors are spawned via `ctx->spawn`, not via messages sent to the guardian).

Create `packages/nexus-http/src/Actor/RequestScopeGuardian.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 *
 * Actor that parents all per-request actor spawns. Stop-only supervision
 * means a crashing per-request actor stops without restart; the awaiting
 * handler observes the crash via MailboxClosedException.
 */
final class RequestScopeGuardian
{
    /** @return Props<object> */
    public static function props(): Props
    {
        return Props::fromBehavior(
            Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same()),
        );
    }

    public const string ACTOR_NAME = '__nexus_http_request_scope_guardian__';
}
```

- [ ] **Step 3:** Implement `PerRequestActorScope`

Create `packages/nexus-http/src/Actor/PerRequestActorScope.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Http\Exception\UnknownActorException;

/**
 * @psalm-api
 *
 * Per-request scope for actors spawned with the PerRequest mode. Lazy:
 * the first spawn() call triggers actor creation; subsequent calls with
 * the same name return the memoized ref. dispose() PoisonPills every
 * spawned actor and is idempotent.
 */
final class PerRequestActorScope
{
    /** @var array<string, ActorRef<object>> */
    private array $spawned = [];

    private bool $disposed = false;

    /**
     * @param array<string, ActorRegistrationEntry> $entries Per-request entries keyed by name.
     */
    public function __construct(
        private readonly ActorSystem $system,
        private readonly array $entries,
        private readonly string $requestId,
    ) {}

    public function spawn(string $name): ActorRef
    {
        if ($this->disposed) {
            throw new UnknownActorException("Scope disposed; cannot spawn '{$name}'");
        }

        if (isset($this->spawned[$name])) {
            return $this->spawned[$name];
        }

        $entry = $this->entries[$name] ?? throw new UnknownActorException($name);

        $actorName = "{$entry->name}-{$this->requestId}";
        $ref = $this->system->spawn($entry->props, $actorName);
        $this->spawned[$name] = $ref;

        return $ref;
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        foreach ($this->spawned as $ref) {
            if ($ref->isAlive()) {
                $ref->tell(new PoisonPill());
            }
        }
    }

    public function hasSpawned(string $name): bool
    {
        return isset($this->spawned[$name]);
    }
}
```

- [ ] **Step 4:** Implement `ResolvedActorTable`

Create `packages/nexus-http/src/Actor/ResolvedActorTable.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Exception\PoolSingletonRequiresWorkerNodeException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\WorkerPool\WorkerNode;

/**
 * @psalm-api
 *
 * Compiled actor lookup table built by HttpApp::compile(). Caches resolved
 * refs for pool-singleton + worker-local modes; provides a factory shape
 * for per-request actors.
 */
final readonly class ResolvedActorTable
{
    /**
     * @param array<string, ActorRef<object>> $resolved
     * @param array<string, ActorRegistrationEntry> $perRequestEntries
     */
    private function __construct(
        private array $resolved,
        private array $perRequestEntries,
    ) {}

    /**
     * @param list<ActorRegistrationEntry> $entries
     */
    public static function build(
        array $entries,
        ActorSystem $system,
        ?WorkerNode $workerNode,
    ): self {
        $resolved = [];
        $perRequest = [];
        $poolSingletonsMissingWorkerNode = [];

        foreach ($entries as $entry) {
            switch ($entry->mode) {
                case ActorMode::PoolSingleton:
                    if ($workerNode === null) {
                        $poolSingletonsMissingWorkerNode[] = $entry->name;
                        break;
                    }
                    $resolved[$entry->name] = $workerNode->spawn($entry->props, $entry->name);
                    break;

                case ActorMode::WorkerLocal:
                    $resolved[$entry->name] = $system->spawn($entry->props, $entry->name);
                    break;

                case ActorMode::PerRequest:
                    $perRequest[$entry->name] = $entry;
                    break;
            }
        }

        if ($poolSingletonsMissingWorkerNode !== []) {
            throw new PoolSingletonRequiresWorkerNodeException($poolSingletonsMissingWorkerNode);
        }

        return new self($resolved, $perRequest);
    }

    public function resolve(string $name): ActorRef
    {
        return $this->resolved[$name] ?? throw new UnknownActorException($name);
    }

    public function isPerRequest(string $name): bool
    {
        return isset($this->perRequestEntries[$name]);
    }

    public function hasAny(string $name): bool
    {
        return isset($this->resolved[$name]) || isset($this->perRequestEntries[$name]);
    }

    /** @return array<string, ActorRegistrationEntry> */
    public function perRequestEntries(): array
    {
        return $this->perRequestEntries;
    }
}
```

- [ ] **Step 5:** Write tests for `ResolvedActorTable`

Create `packages/nexus-http/tests/Unit/Actor/ResolvedActorTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistrationEntry;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Exception\PoolSingletonRequiresWorkerNodeException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedActorTable::class)]
final class ResolvedActorTableTest extends TestCase
{
    private function noopProps(): Props
    {
        return Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
    }

    #[Test]
    public function worker_local_actor_is_spawned_during_build(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('store', $this->noopProps(), ActorMode::WorkerLocal, null, null);

        $table = ResolvedActorTable::build([$entry], $system, workerNode: null);

        $ref = $table->resolve('store');
        self::assertTrue($ref->isAlive());
        self::assertSame('/user/store', (string) $ref->path());
    }

    #[Test]
    public function per_request_actor_is_registered_but_not_spawned(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('saga', $this->noopProps(), ActorMode::PerRequest, null, null);

        $table = ResolvedActorTable::build([$entry], $system, workerNode: null);

        self::assertTrue($table->isPerRequest('saga'));
        $this->expectException(UnknownActorException::class);
        $table->resolve('saga');
    }

    #[Test]
    public function pool_singleton_without_worker_node_throws(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('store', $this->noopProps(), ActorMode::PoolSingleton, null, null);

        $this->expectException(PoolSingletonRequiresWorkerNodeException::class);
        $this->expectExceptionMessageMatches('/store/');
        ResolvedActorTable::build([$entry], $system, workerNode: null);
    }
}
```

- [ ] **Step 6:** Write tests for `PerRequestActorScope`

Create `packages/nexus-http/tests/Unit/Actor/PerRequestActorScopeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistrationEntry;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PerRequestActorScope::class)]
final class PerRequestActorScopeTest extends TestCase
{
    private function noopProps(): Props
    {
        return Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
    }

    #[Test]
    public function spawn_creates_actor_and_memoizes_it(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('saga', $this->noopProps(), ActorMode::PerRequest, null, null);
        $scope = new PerRequestActorScope($system, ['saga' => $entry], 'r-1');

        $a = $scope->spawn('saga');
        $b = $scope->spawn('saga');

        self::assertSame($a, $b);
        self::assertTrue($scope->hasSpawned('saga'));
        self::assertSame('/user/saga-r-1', (string) $a->path());
    }

    #[Test]
    public function dispose_is_idempotent_and_noop_when_nothing_spawned(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');

        $scope->dispose();
        $scope->dispose();

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function spawn_after_dispose_throws(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('saga', $this->noopProps(), ActorMode::PerRequest, null, null);
        $scope = new PerRequestActorScope($system, ['saga' => $entry], 'r-1');

        $scope->dispose();

        $this->expectException(UnknownActorException::class);
        $scope->spawn('saga');
    }

    #[Test]
    public function unknown_name_throws(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');

        $this->expectException(UnknownActorException::class);
        $scope->spawn('nope');
    }
}
```

- [ ] **Step 7:** Run all phase-6 tests

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Actor/`
Expected: PASS.

- [ ] **Step 8:** Run lint

Run: `make psalm && make phpcs`
Expected: clean.

- [ ] **Step 9:** Commit

```bash
git add packages/nexus-http/src/Actor/ResolvedActorTable.php \
        packages/nexus-http/src/Actor/PerRequestActorScope.php \
        packages/nexus-http/src/Actor/RequestScopeGuardian.php \
        packages/nexus-http/src/Exception/UnknownActorException.php \
        packages/nexus-http/src/Exception/PoolSingletonRequiresWorkerNodeException.php \
        packages/nexus-http/src/Exception/PerRequestActorInConstructorException.php \
        packages/nexus-http/tests/Unit/Actor/ResolvedActorTableTest.php \
        packages/nexus-http/tests/Unit/Actor/PerRequestActorScopeTest.php
git -c commit.gpgsign=false commit -m "feat(http): add ResolvedActorTable + PerRequestActorScope

Compile-time resolution of actor refs: worker-local spawned during
build, pool-singleton resolved through injected WorkerNode (fails fast
when missing), per-request entries deferred to PerRequestActorScope.
Scope is lazy + idempotent dispose. Naming: {name}-{requestId}."
```

---

## Phase 7: Handler resolution — #[FromActor], #[FromService], HandlerResolver, ResolvedHandler

**Outcome:** `#[FromActor]` and `#[FromService]` attributes defined. `HandlerResolver` walks reflection ONCE per handler class/closure at compile time and emits a `ResolvedHandler` — a closure that takes `(ServerRequestInterface, PerRequestActorScope, array $pathParams)` and returns `ResponseInterface` or `Future`. All validation rules from the spec are enforced here.

`#[FromService]` lets handlers and middleware inject any PSR-11 container service alongside actor injection. Explicit `#[FromService('service.id')]` for string-id binding; bare `#[FromService]` for type-based binding (uses the parameter's type-hint as the container key). Works on constructor params and method/invoke params.

**Files:**
- Create: `packages/nexus-http/src/Handler/Attribute/FromActor.php`
- Create: `packages/nexus-http/src/Handler/Attribute/FromService.php`
- Create: `packages/nexus-http/src/Handler/ResolvedHandler.php`
- Create: `packages/nexus-http/src/Handler/HandlerMetadata.php`
- Create: `packages/nexus-http/src/Handler/HandlerResolver.php`
- Create: `packages/nexus-http/tests/Unit/Handler/HandlerResolverTest.php`

- [ ] **Step 1:** Implement `#[FromActor]` attribute

Create `packages/nexus-http/src/Handler/Attribute/FromActor.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Inject an actor reference registered with HttpApp. Works on constructor
 * parameters (constructor injection) and on handler/middleware invocation
 * method parameters.
 *
 * Constructor params may reference PoolSingleton or WorkerLocal actors only.
 * Method params may reference any mode including PerRequest.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromActor
{
    public function __construct(public string $name) {}
}
```

Also create `packages/nexus-http/src/Handler/Attribute/FromService.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Inject a service from the PSR-11 container.
 *
 *   #[FromService('logger.audit')] LoggerInterface $log    // resolve by container id
 *   #[FromService] MyService $service                       // resolve by type
 *
 * Works on constructor params and on handler/middleware invocation method
 * params. Requires a ContainerInterface to be supplied to HttpApp::create().
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromService
{
    public function __construct(public ?string $id = null) {}
}
```

- [ ] **Step 2:** Implement `HandlerMetadata` (compile-time snapshot of a handler's wiring)

Create `packages/nexus-http/src/Handler/HandlerMetadata.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler;

/**
 * @psalm-api
 *
 * Reflection results for one handler class — captured once at compile.
 * Cache-friendly: every field is a var_export-safe scalar/array.
 */
final readonly class HandlerMetadata
{
    /**
     * @param list<ParamMetadata> $ctorParams
     * @param list<ParamMetadata> $invokeParams
     */
    public function __construct(
        public string $className,
        public string $invokeMethod,           // '__invoke' or 'handle' or 'show' etc.
        public array $ctorParams,
        public array $invokeParams,
        public bool $returnIsFuture,
        public bool $needsRequestScope,        // true iff any invokeParam has FromActor pointing to PerRequest
    ) {}
}
```

Also create `packages/nexus-http/src/Handler/ParamMetadata.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler;

/** @psalm-api */
final readonly class ParamMetadata
{
    public const string KIND_SERVER_REQUEST  = 'server_request';
    public const string KIND_PATH_PARAM      = 'path_param';
    public const string KIND_FROM_ACTOR      = 'from_actor';
    public const string KIND_FROM_SERVICE    = 'from_service';
    public const string KIND_CONTAINER       = 'container';
    public const string KIND_REQUEST_SCOPE   = 'request_scope';

    public function __construct(
        public string $name,
        public ?string $type,
        public string $kind,
        public ?string $actorName = null,
        public ?string $serviceId = null,
    ) {}
}
```

- [ ] **Step 3:** Implement `ResolvedHandler`

Create `packages/nexus-http/src/Handler/ResolvedHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler;

use Closure;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Runtime\Async\Future;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Compiled handler. The hot path calls invoke($r, $scope, $pathParams) once.
 * Returns either ResponseInterface or Future<ResponseInterface>.
 */
final readonly class ResolvedHandler
{
    /**
     * @param Closure(ServerRequestInterface, PerRequestActorScope, array<string, string>): (ResponseInterface|Future) $invoke
     */
    public function __construct(
        public Closure $invoke,
        public bool $returnsResponse,   // false => returns Future<ResponseInterface>
        public bool $needsRequestScope,
    ) {}
}
```

- [ ] **Step 4:** Implement `HandlerResolver`

Create `packages/nexus-http/src/Handler/HandlerResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler;

use Closure;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Exception\PerRequestActorInConstructorException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Runtime\Async\Future;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Walks a handler (class string, 'Class::method' string, or Closure) and
 * produces a ResolvedHandler. Reflection happens exactly once per handler.
 */
final class HandlerResolver
{
    public function __construct(
        private readonly ResolvedActorTable $actors,
        private readonly ?ContainerInterface $container,
    ) {}

    public function resolve(string|Closure $handler): ResolvedHandler
    {
        if ($handler instanceof Closure) {
            return $this->resolveClosure($handler);
        }

        if (str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
            return $this->resolveClassMethod($class, $method);
        }

        // Class with __invoke or implementing RequestHandlerInterface
        return $this->resolveInvokableClass($handler);
    }

    private function resolveClosure(Closure $closure): ResolvedHandler
    {
        $reflection = new ReflectionFunction($closure);
        $invokeParams = $this->describeParams($reflection->getParameters(), inConstructor: false, owner: 'closure');
        $returnIsFuture = $this->returnsFuture($reflection->getReturnType());
        $needsScope = $this->paramsNeedScope($invokeParams);

        $invoke = function (
            ServerRequestInterface $r,
            PerRequestActorScope $scope,
            array $pathParams,
        ) use ($closure, $invokeParams): mixed {
            $args = $this->buildArgs($invokeParams, $r, $scope, $pathParams);
            return $closure(...$args);
        };

        return new ResolvedHandler(Closure::fromCallable($invoke), !$returnIsFuture, $needsScope);
    }

    private function resolveInvokableClass(string $class): ResolvedHandler
    {
        $reflection = new ReflectionClass($class);
        $method = $reflection->hasMethod('__invoke') ? '__invoke'
            : ($reflection->implementsInterface(RequestHandlerInterface::class) ? 'handle'
            : throw new \LogicException("{$class} must declare __invoke() or implement RequestHandlerInterface"));

        return $this->resolveClassMethod($class, $method);
    }

    private function resolveClassMethod(string $class, string $method): ResolvedHandler
    {
        $reflection = new ReflectionClass($class);
        $ctor = $reflection->getConstructor();
        $ctorParams = $ctor !== null
            ? $this->describeParams($ctor->getParameters(), inConstructor: true, owner: $class)
            : [];

        $methodRef = $reflection->getMethod($method);
        $invokeParams = $this->describeParams($methodRef->getParameters(), inConstructor: false, owner: $class);
        $returnIsFuture = $this->returnsFuture($methodRef->getReturnType());
        $needsScope = $this->paramsNeedScope($invokeParams);

        // Pre-build constructor args once per worker thread (singleton handler instance).
        $instance = $this->instantiate($class, $ctorParams);

        $invoke = function (
            ServerRequestInterface $r,
            PerRequestActorScope $scope,
            array $pathParams,
        ) use ($instance, $method, $invokeParams): mixed {
            $args = $this->buildArgs($invokeParams, $r, $scope, $pathParams);
            return $instance->{$method}(...$args);
        };

        return new ResolvedHandler(Closure::fromCallable($invoke), !$returnIsFuture, $needsScope);
    }

    /**
     * @param list<ParamMetadata> $ctorParams
     */
    private function instantiate(string $class, array $ctorParams): object
    {
        if ($this->container !== null && $this->container->has($class)) {
            /** @var object */
            return $this->container->get($class);
        }

        $args = [];
        foreach ($ctorParams as $p) {
            $args[] = match ($p->kind) {
                ParamMetadata::KIND_FROM_ACTOR   => $this->actors->resolve($p->actorName ?? ''),
                ParamMetadata::KIND_FROM_SERVICE => $this->container?->get($p->serviceId ?? $p->type ?? '')
                    ?? throw new \LogicException("Cannot resolve {$class}::{$p->name} via #[FromService] without a container"),
                ParamMetadata::KIND_CONTAINER    => $this->container?->get($p->type ?? '')
                    ?? throw new \LogicException("Cannot resolve {$class}::{$p->name} without a container"),
                default => throw new \LogicException("Unsupported constructor param kind: {$p->kind}"),
            };
        }

        return new $class(...$args);
    }

    /**
     * @param array<int, ReflectionParameter> $params
     * @return list<ParamMetadata>
     */
    private function describeParams(array $params, bool $inConstructor, string $owner): array
    {
        $out = [];
        foreach ($params as $p) {
            $name = $p->getName();
            $type = ($p->getType() instanceof ReflectionNamedType) ? $p->getType()->getName() : null;

            $fromActor = $p->getAttributes(FromActor::class);
            if ($fromActor !== []) {
                $actorName = $fromActor[0]->newInstance()->name;
                if (!$this->actors->hasAny($actorName)) {
                    throw new UnknownActorException($actorName);
                }
                if ($inConstructor && $this->actors->isPerRequest($actorName)) {
                    throw new PerRequestActorInConstructorException($owner, $name, $actorName);
                }
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_FROM_ACTOR, $actorName);
                continue;
            }

            $fromService = $p->getAttributes(FromService::class);
            if ($fromService !== []) {
                $serviceId = $fromService[0]->newInstance()->id;
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_FROM_SERVICE, serviceId: $serviceId);
                continue;
            }

            if ($type === ServerRequestInterface::class) {
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_SERVER_REQUEST);
                continue;
            }

            if ($type === PerRequestActorScope::class) {
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_REQUEST_SCOPE);
                continue;
            }

            // Treat unattributed string-typed params as path-param injection by name.
            if ($type === 'string' && !$inConstructor) {
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_PATH_PARAM);
                continue;
            }

            // Constructor without explicit FromActor: assume container resolution by type.
            if ($inConstructor) {
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_CONTAINER);
                continue;
            }

            throw new \LogicException(
                "Cannot resolve {$owner} parameter \${$name}: add #[FromActor], "
                . "type-hint ServerRequestInterface, PerRequestActorScope, or use string for path params"
            );
        }

        return $out;
    }

    /**
     * @param list<ParamMetadata> $params
     * @return list<mixed>
     */
    private function buildArgs(
        array $params,
        ServerRequestInterface $r,
        PerRequestActorScope $scope,
        array $pathParams,
    ): array {
        $args = [];
        foreach ($params as $p) {
            $args[] = match ($p->kind) {
                ParamMetadata::KIND_SERVER_REQUEST => $r,
                ParamMetadata::KIND_REQUEST_SCOPE  => $scope,
                ParamMetadata::KIND_PATH_PARAM     => $pathParams[$p->name] ?? '',
                ParamMetadata::KIND_FROM_ACTOR     => $this->actors->isPerRequest($p->actorName ?? '')
                    ? $scope->spawn($p->actorName ?? '')
                    : $this->actors->resolve($p->actorName ?? ''),
                ParamMetadata::KIND_FROM_SERVICE   => $this->container?->get($p->serviceId ?? $p->type ?? '')
                    ?? throw new \LogicException("Cannot resolve param \${$p->name} via #[FromService] without a container"),
                ParamMetadata::KIND_CONTAINER => $this->container?->get($p->type ?? '')
                    ?? throw new \LogicException("Cannot resolve param \${$p->name} without a container"),
            };
        }

        return $args;
    }

    /** @param list<ParamMetadata> $params */
    private function paramsNeedScope(array $params): bool
    {
        foreach ($params as $p) {
            if ($p->kind === ParamMetadata::KIND_REQUEST_SCOPE) {
                return true;
            }
            if ($p->kind === ParamMetadata::KIND_FROM_ACTOR && $this->actors->isPerRequest($p->actorName ?? '')) {
                return true;
            }
        }
        return false;
    }

    private function returnsFuture(?\ReflectionType $type): bool
    {
        if (!$type instanceof ReflectionNamedType) {
            return false;
        }
        return $type->getName() === Future::class;
    }
}
```

- [ ] **Step 5:** Write tests for `HandlerResolver`

Create `packages/nexus-http/tests/Unit/Handler/HandlerResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistrationEntry;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Exception\PerRequestActorInConstructorException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\HandlerResolver;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Test fixtures
final class _ClosureFixtureClass {}

final class _InjectsWorkerLocalCtor
{
    public function __construct(
        #[FromActor('store')] public ActorRef $store,
    ) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}

final class _InjectsPerRequestInCtor
{
    public function __construct(
        #[FromActor('saga')] public ActorRef $saga,
    ) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}

#[CoversClass(HandlerResolver::class)]
final class HandlerResolverTest extends TestCase
{
    private function noopProps(): Props
    {
        return Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
    }

    private function buildResolver(): array
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([
            new ActorRegistrationEntry('store', $this->noopProps(), ActorMode::WorkerLocal, null, null),
            new ActorRegistrationEntry('saga',  $this->noopProps(), ActorMode::PerRequest,  null, null),
        ], $system, null);

        return [$system, new HandlerResolver($table, null)];
    }

    #[Test]
    public function resolves_closure_with_server_request_param(): void
    {
        [, $resolver] = $this->buildResolver();
        $handler = static fn(ServerRequestInterface $r): ResponseInterface => Response::ok();

        $resolved = $resolver->resolve($handler);

        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');
        $response = ($resolved->invoke)(new ServerRequest('GET', '/'), $scope, []);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($resolved->needsRequestScope);
    }

    #[Test]
    public function resolves_class_with_ctor_actor_injection(): void
    {
        [, $resolver] = $this->buildResolver();

        $resolved = $resolver->resolve(_InjectsWorkerLocalCtor::class);

        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');
        $response = ($resolved->invoke)(new ServerRequest('GET', '/'), $scope, []);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function constructor_per_request_injection_throws(): void
    {
        [, $resolver] = $this->buildResolver();

        $this->expectException(PerRequestActorInConstructorException::class);
        $resolver->resolve(_InjectsPerRequestInCtor::class);
    }

    #[Test]
    public function unknown_actor_in_attribute_throws(): void
    {
        [, $resolver] = $this->buildResolver();

        $this->expectException(UnknownActorException::class);
        $resolver->resolve(static fn(
            ServerRequestInterface $r,
            #[FromActor('does-not-exist')] ActorRef $what,
        ): ResponseInterface => Response::ok());
    }

    #[Test]
    public function method_per_request_injection_marks_needs_request_scope(): void
    {
        [, $resolver] = $this->buildResolver();

        $resolved = $resolver->resolve(static fn(
            ServerRequestInterface $r,
            #[FromActor('saga')] ActorRef $saga,
        ): ResponseInterface => Response::ok());

        self::assertTrue($resolved->needsRequestScope);
    }
}
```

- [ ] **Step 6:** Run all phase-7 tests

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/`
Expected: PASS.

- [ ] **Step 7:** Run lint

Run: `make psalm && make phpcs`
Expected: clean.

- [ ] **Step 8:** Commit

```bash
git add packages/nexus-http/src/Handler/ packages/nexus-http/tests/Unit/Handler/
git -c commit.gpgsign=false commit -m "feat(http): add HandlerResolver, #[FromActor], ResolvedHandler

Reflection happens once per handler at compile time. Closures, classes
with __invoke, RequestHandlerInterface implementations, and
'Class::method' strings all supported. Constructor #[FromActor] cannot
reference per-request actors (compile-time check). Method params with
per-request actors mark the handler needsRequestScope=true."
```

---

## Phase 8: HTTP exceptions + mapper registry

**Outcome:** `HttpException` base + factory methods. `RouteNotFoundException`, `MethodNotAllowedException`. `ExceptionMapperRegistry` with class-hierarchy walk and default mappers for the built-in cases from spec section 11.

**Files:**
- Create: `packages/nexus-http/src/Exception/HttpException.php`
- Create: `packages/nexus-http/src/Exception/RouteNotFoundException.php`
- Create: `packages/nexus-http/src/Exception/MethodNotAllowedException.php`
- Create: `packages/nexus-http/src/Exception/ExceptionMapperRegistry.php`
- Create: `packages/nexus-http/src/Exception/DefaultMappers.php`
- Create: `packages/nexus-http/src/App/ErrorMode.php`
- Create: `packages/nexus-http/tests/Unit/Exception/HttpExceptionTest.php`
- Create: `packages/nexus-http/tests/Unit/Exception/ExceptionMapperRegistryTest.php`

- [ ] **Step 1:** Implement `HttpException`

Create `packages/nexus-http/src/Exception/HttpException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Throwable;

/**
 * @psalm-api
 *
 * Base for HTTP-aware exceptions. The status code is used directly
 * by the default exception mapper.
 */
class HttpException extends NexusException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(404, $message);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(403, $message);
    }

    public static function unprocessableEntity(array $errors): self
    {
        return new self(422, json_encode(['errors' => $errors], JSON_UNESCAPED_SLASHES));
    }

    public static function conflict(string $message = 'Conflict'): self
    {
        return new self(409, $message);
    }
}
```

- [ ] **Step 2:** Implement `RouteNotFoundException` + `MethodNotAllowedException`

`packages/nexus-http/src/Exception/RouteNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

/** @psalm-api */
final class RouteNotFoundException extends HttpException
{
    public function __construct(string $method, string $path)
    {
        parent::__construct(404, "No route for {$method} {$path}");
    }
}
```

`packages/nexus-http/src/Exception/MethodNotAllowedException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

/** @psalm-api */
final class MethodNotAllowedException extends HttpException
{
    /** @param list<string> $allowed */
    public function __construct(string $method, string $path, public readonly array $allowed)
    {
        parent::__construct(
            405,
            "Method {$method} not allowed for {$path}; allowed: " . implode(', ', $allowed),
            ['Allow' => implode(', ', $allowed)],
        );
    }
}
```

- [ ] **Step 3:** Implement `ErrorMode`

Create `packages/nexus-http/src/App/ErrorMode.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\App;

/** @psalm-api */
enum ErrorMode
{
    case Production;
    case Development;
}
```

- [ ] **Step 4:** Implement `ExceptionMapperRegistry`

Create `packages/nexus-http/src/Exception/ExceptionMapperRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Registered mappers from exception class to ResponseInterface. Walk order:
 * class → parents → interfaces. First exact-class match wins; otherwise the
 * first ancestor or interface that has a registered mapper wins.
 */
final class ExceptionMapperRegistry
{
    /** @var array<class-string, Closure(Throwable, ServerRequestInterface): ResponseInterface> */
    private array $mappers = [];

    /** @param Closure(Throwable, ServerRequestInterface): ResponseInterface $mapper */
    public function register(string $exceptionClass, Closure $mapper): void
    {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->mappers[$exceptionClass] = $mapper;
    }

    public function map(Throwable $e, ServerRequestInterface $r): ResponseInterface
    {
        $mapper = $this->find($e);
        return $mapper($e, $r);
    }

    public function has(string $exceptionClass): bool
    {
        return isset($this->mappers[$exceptionClass]);
    }

    private function find(Throwable $e): Closure
    {
        $class = $e::class;
        if (isset($this->mappers[$class])) {
            return $this->mappers[$class];
        }

        foreach (class_parents($class) ?: [] as $parent) {
            if (isset($this->mappers[$parent])) {
                return $this->mappers[$parent];
            }
        }

        foreach (class_implements($class) ?: [] as $interface) {
            if (isset($this->mappers[$interface])) {
                return $this->mappers[$interface];
            }
        }

        if (isset($this->mappers[Throwable::class])) {
            return $this->mappers[Throwable::class];
        }

        throw new \LogicException("No exception mapper for {$class} and no Throwable fallback registered.");
    }
}
```

- [ ] **Step 5:** Implement `DefaultMappers`

Create `packages/nexus-http/src/Exception/DefaultMappers.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxOverflowException;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Registers the built-in defaults. User registrations override on exact class.
 */
final class DefaultMappers
{
    public static function registerInto(ExceptionMapperRegistry $registry, ErrorMode $mode): void
    {
        $registry->register(
            HttpException::class,
            static fn(Throwable $e, ServerRequestInterface $r): ResponseInterface => self::fromHttpException($e),
        );

        $registry->register(
            AskTimeoutException::class,
            static fn(): ResponseInterface => Response::gatewayTimeout(),
        );

        $registry->register(
            MailboxOverflowException::class,
            static fn(): ResponseInterface => Response::serviceUnavailable(Duration::seconds(1)),
        );

        $registry->register(
            MailboxClosedException::class,
            static fn(): ResponseInterface => Response::serviceUnavailable(),
        );

        $registry->register(
            Throwable::class,
            static fn(Throwable $e): ResponseInterface => match ($mode) {
                ErrorMode::Development => JsonResponse::ok([
                    'error'   => 'Internal Server Error',
                    'class'   => $e::class,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => explode("\n", $e->getTraceAsString()),
                ])->withStatus(500),
                ErrorMode::Production => JsonResponse::ok(['error' => 'Internal Server Error'])->withStatus(500),
            },
        );
    }

    private static function fromHttpException(Throwable $e): ResponseInterface
    {
        assert($e instanceof HttpException);
        $response = new Psr7Response($e->status, $e->headers, $e->getMessage());
        return $response;
    }
}
```

- [ ] **Step 6:** Write tests

Create `packages/nexus-http/tests/Unit/Exception/HttpExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Exception;

use Monadial\Nexus\Http\Exception\HttpException;
use Monadial\Nexus\Http\Exception\MethodNotAllowedException;
use Monadial\Nexus\Http\Exception\RouteNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpException::class)]
#[CoversClass(MethodNotAllowedException::class)]
#[CoversClass(RouteNotFoundException::class)]
final class HttpExceptionTest extends TestCase
{
    #[Test]
    public function not_found_factory_returns_404(): void
    {
        $e = HttpException::notFound('User');
        self::assertSame(404, $e->status);
        self::assertSame('User', $e->getMessage());
    }

    #[Test]
    public function method_not_allowed_carries_allow_header(): void
    {
        $e = new MethodNotAllowedException('POST', '/users', ['GET', 'PUT']);
        self::assertSame(405, $e->status);
        self::assertSame('GET, PUT', $e->headers['Allow']);
    }

    #[Test]
    public function unprocessable_entity_serializes_errors(): void
    {
        $e = HttpException::unprocessableEntity(['email' => 'invalid']);
        self::assertSame(422, $e->status);
        self::assertStringContainsString('"email":"invalid"', $e->getMessage());
    }
}
```

Create `packages/nexus-http/tests/Unit/Exception/ExceptionMapperRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Exception;

use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Exception\DefaultMappers;
use Monadial\Nexus\Http\Exception\ExceptionMapperRegistry;
use Monadial\Nexus\Http\Exception\HttpException;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

#[CoversClass(ExceptionMapperRegistry::class)]
#[CoversClass(DefaultMappers::class)]
final class ExceptionMapperRegistryTest extends TestCase
{
    #[Test]
    public function maps_exact_class_match(): void
    {
        $registry = new ExceptionMapperRegistry();
        $registry->register(RuntimeException::class, static fn(): ResponseInterface => Response::badRequest());

        $r = $registry->map(new RuntimeException('boom'), new ServerRequest('GET', '/'));
        self::assertSame(400, $r->getStatusCode());
    }

    #[Test]
    public function falls_back_to_parent_class_mapper(): void
    {
        $registry = new ExceptionMapperRegistry();
        $registry->register(Throwable::class, static fn(): ResponseInterface => Response::internalServerError());

        $r = $registry->map(new RuntimeException('boom'), new ServerRequest('GET', '/'));
        self::assertSame(500, $r->getStatusCode());
    }

    #[Test]
    public function http_exception_uses_carried_status(): void
    {
        $registry = new ExceptionMapperRegistry();
        DefaultMappers::registerInto($registry, ErrorMode::Production);

        $r = $registry->map(HttpException::notFound('nope'), new ServerRequest('GET', '/'));
        self::assertSame(404, $r->getStatusCode());
        self::assertSame('nope', (string) $r->getBody());
    }

    #[Test]
    public function dev_mode_includes_trace_in_500_body(): void
    {
        $registry = new ExceptionMapperRegistry();
        DefaultMappers::registerInto($registry, ErrorMode::Development);

        $r = $registry->map(new RuntimeException('boom'), new ServerRequest('GET', '/'));
        self::assertSame(500, $r->getStatusCode());
        self::assertStringContainsString('"boom"', (string) $r->getBody());
        self::assertStringContainsString('"trace"', (string) $r->getBody());
    }
}
```

- [ ] **Step 7:** Run all phase-8 tests

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Exception/`
Expected: PASS.

- [ ] **Step 8:** Lint + commit

Run: `make psalm && make phpcs`

```bash
git add packages/nexus-http/src/Exception/ \
        packages/nexus-http/src/App/ErrorMode.php \
        packages/nexus-http/tests/Unit/Exception/
git -c commit.gpgsign=false commit -m "feat(http): add HttpException, ExceptionMapperRegistry, DefaultMappers

HttpException factories: notFound, unauthorized, forbidden,
unprocessableEntity, conflict. RouteNotFoundException + MethodNot
AllowedException carry Allow header. Mapper registry walks
class → parents → interfaces → Throwable. ErrorMode controls
Production-vs-Development 500-body shape."
```

---

## Phase 9: Middleware infrastructure

**Outcome:** `MiddlewarePipeline` assembles a PSR-15 stack from class names. `RouterMiddleware` is the innermost middleware — it dispatches the request, attaches path params + per-request scope, and invokes the `ResolvedHandler`. `ExceptionHandlerMiddleware` wraps the whole thing in a try/catch and runs the mapper.

**Files:**
- Create: `packages/nexus-http/src/Middleware/MiddlewarePipeline.php`
- Create: `packages/nexus-http/src/Middleware/RouterMiddleware.php`
- Create: `packages/nexus-http/src/Middleware/ExceptionHandlerMiddleware.php`
- Create: `packages/nexus-http/src/Middleware/MiddlewareInvoker.php`
- Create: `packages/nexus-http/tests/Unit/Middleware/MiddlewarePipelineTest.php`
- Create: `packages/nexus-http/tests/Unit/Middleware/RouterMiddlewareTest.php`
- Create: `packages/nexus-http/tests/Unit/Middleware/ExceptionHandlerMiddlewareTest.php`

- [ ] **Step 1:** Implement `MiddlewareInvoker` — the inner-handler shim

Create `packages/nexus-http/src/Middleware/MiddlewareInvoker.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Closure;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Internal PSR-15 RequestHandlerInterface that walks a list of middlewares
 * and ends in a tail closure. Each next() call advances the index.
 */
final class MiddlewareInvoker implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     * @param Closure(ServerRequestInterface): ResponseInterface $tail
     */
    public function __construct(
        private readonly array $middlewares,
        private readonly Closure $tail,
        private readonly int $index = 0,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middlewares[$this->index])) {
            return ($this->tail)($request);
        }

        $next = new self($this->middlewares, $this->tail, $this->index + 1);
        return $this->middlewares[$this->index]->process($request, $next);
    }
}
```

- [ ] **Step 2:** Implement `MiddlewarePipeline`

Create `packages/nexus-http/src/Middleware/MiddlewarePipeline.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * @psalm-api
 *
 * Resolves middleware class strings to MiddlewareInterface instances and
 * runs the PSR-15 chain.
 */
final class MiddlewarePipeline
{
    /** @var array<class-string, MiddlewareInterface> */
    private array $instances = [];

    public function __construct(private readonly ?ContainerInterface $container) {}

    /**
     * @param list<string|MiddlewareInterface> $middlewares
     * @param Closure(ServerRequestInterface): ResponseInterface $tail
     */
    public function process(array $middlewares, ServerRequestInterface $request, Closure $tail): ResponseInterface
    {
        $resolved = [];
        foreach ($middlewares as $mw) {
            $resolved[] = $mw instanceof MiddlewareInterface ? $mw : $this->resolve($mw);
        }

        return (new MiddlewareInvoker($resolved, $tail))->handle($request);
    }

    private function resolve(string $class): MiddlewareInterface
    {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }

        if ($this->container !== null && $this->container->has($class)) {
            /** @var MiddlewareInterface $instance */
            $instance = $this->container->get($class);
        } else {
            /** @var MiddlewareInterface $instance */
            $instance = new $class();
        }

        $this->instances[$class] = $instance;
        return $instance;
    }
}
```

- [ ] **Step 3:** Implement `RouterMiddleware`

Create `packages/nexus-http/src/Middleware/RouterMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Event\RouteMatched;
use Monadial\Nexus\Http\Exception\MethodNotAllowedException;
use Monadial\Nexus\Http\Exception\RouteNotFoundException;
use Monadial\Nexus\Http\Handler\ResolvedHandler;
use Monadial\Nexus\Http\Routing\DispatchResult;
use Monadial\Nexus\Http\Routing\Dispatcher;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Runtime\Async\Future;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * @psalm-api
 *
 * Innermost middleware. Dispatches the request, builds the per-request scope
 * lazily (only if the route needs one), runs route-level middlewares, calls
 * the handler, awaits Future if applicable, and disposes the scope in finally.
 */
final class RouterMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, ResolvedHandler> $handlersByRouteKey  key = "METHOD:path"
     * @param array<string, list<string>> $routeMiddlewaresByKey
     */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly array $handlersByRouteKey,
        private readonly array $routeMiddlewaresByKey,
        private readonly MiddlewarePipeline $pipeline,
        private readonly ActorSystem $system,
        private readonly ResolvedActorTable $actors,
        private readonly ?EventDispatcherInterface $events,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

        match ($result->status) {
            DispatchResult::NOT_FOUND          => throw new RouteNotFoundException($request->getMethod(), $request->getUri()->getPath()),
            DispatchResult::METHOD_NOT_ALLOWED => throw new MethodNotAllowedException($request->getMethod(), $request->getUri()->getPath(), $result->allowedMethods),
            DispatchResult::FOUND              => null,
        };

        /** @var Route $route */
        $route = $result->route;
        $key = $this->routeKey($route);
        $resolved = $this->handlersByRouteKey[$key];

        foreach ($result->pathParams as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        $requestId = $request->getHeaderLine('X-Request-Id');
        if ($requestId === '') {
            $requestId = (string) new Ulid();
        }

        $scope = new PerRequestActorScope($this->system, $this->actors->perRequestEntries(), $requestId);
        $request = $request->withAttribute(PerRequestActorScope::class, $scope);

        $this->events?->dispatch(new RouteMatched($request, $route, $result->pathParams));

        try {
            $tail = function (ServerRequestInterface $r) use ($resolved, $scope, $result): ResponseInterface {
                $out = ($resolved->invoke)($r, $scope, $result->pathParams);
                return $out instanceof Future ? $out->await() : $out;
            };

            return $this->pipeline->process(
                $this->routeMiddlewaresByKey[$key] ?? [],
                $request,
                $tail,
            );
        } finally {
            $scope->dispose();
        }
    }

    private function routeKey(Route $route): string
    {
        return $route->method . ':' . $route->path;
    }
}
```

- [ ] **Step 4:** Implement `ExceptionHandlerMiddleware`

Create `packages/nexus-http/src/Middleware/ExceptionHandlerMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Monadial\Nexus\Http\Exception\ExceptionMapperRegistry;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @psalm-api
 *
 * Outermost middleware by default. Catches everything below and runs the
 * mapper registry. Logs with PSR-3 (NullLogger if none supplied).
 */
final class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ExceptionMapperRegistry $mappers,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            ($this->logger ?? new NullLogger())->error('HTTP exception', [
                'exception' => $e,
                'path'      => $request->getUri()->getPath(),
                'method'    => $request->getMethod(),
            ]);
            return $this->mappers->map($e, $request);
        }
    }
}
```

- [ ] **Step 5:** Stub the `RouteMatched` event (full PSR-14 events come in phase 13; we need just this one for the middleware)

Create `packages/nexus-http/src/Event/RouteMatched.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Event;

use Monadial\Nexus\Http\Routing\Route;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class RouteMatched
{
    /** @param array<string, string> $pathParams */
    public function __construct(
        public ServerRequestInterface $request,
        public Route $route,
        public array $pathParams,
    ) {}
}
```

- [ ] **Step 6:** Tests for `MiddlewarePipeline`

Create `packages/nexus-http/tests/Unit/Middleware/MiddlewarePipelineTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class _AddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $name, private readonly string $value) {}

    #[Override]
    public function process(ServerRequestInterface $r, RequestHandlerInterface $next): ResponseInterface
    {
        return $next->handle($r)->withHeader($this->name, $this->value);
    }
}

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    #[Test]
    public function runs_chain_outside_in_response_unwinds_inside_out(): void
    {
        $pipeline = new MiddlewarePipeline(container: null);

        $response = $pipeline->process(
            [new _AddHeaderMiddleware('X-A', '1'), new _AddHeaderMiddleware('X-B', '2')],
            new ServerRequest('GET', '/'),
            static fn(): ResponseInterface => Response::ok(),
        );

        self::assertSame('1', $response->getHeaderLine('X-A'));
        self::assertSame('2', $response->getHeaderLine('X-B'));
    }

    #[Test]
    public function empty_chain_calls_tail_directly(): void
    {
        $pipeline = new MiddlewarePipeline(container: null);

        $response = $pipeline->process(
            [],
            new ServerRequest('GET', '/'),
            static fn(): ResponseInterface => new Psr7Response(204),
        );

        self::assertSame(204, $response->getStatusCode());
    }
}
```

- [ ] **Step 7:** Tests for `ExceptionHandlerMiddleware`

Create `packages/nexus-http/tests/Unit/Middleware/ExceptionHandlerMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Middleware;

use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Exception\DefaultMappers;
use Monadial\Nexus\Http\Exception\ExceptionMapperRegistry;
use Monadial\Nexus\Http\Exception\HttpException;
use Monadial\Nexus\Http\Middleware\ExceptionHandlerMiddleware;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class _ThrowingHandler implements RequestHandlerInterface
{
    public function __construct(private readonly \Throwable $error) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw $this->error;
    }
}

#[CoversClass(ExceptionHandlerMiddleware::class)]
final class ExceptionHandlerMiddlewareTest extends TestCase
{
    #[Test]
    public function maps_http_exception_to_response(): void
    {
        $mappers = new ExceptionMapperRegistry();
        DefaultMappers::registerInto($mappers, ErrorMode::Production);
        $mw = new ExceptionHandlerMiddleware($mappers);

        $response = $mw->process(
            new ServerRequest('GET', '/u'),
            new _ThrowingHandler(HttpException::notFound('nope')),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function falls_back_to_throwable_mapper_for_unmapped(): void
    {
        $mappers = new ExceptionMapperRegistry();
        DefaultMappers::registerInto($mappers, ErrorMode::Production);
        $mw = new ExceptionHandlerMiddleware($mappers);

        $response = $mw->process(
            new ServerRequest('GET', '/u'),
            new _ThrowingHandler(new RuntimeException('boom')),
        );

        self::assertSame(500, $response->getStatusCode());
    }
}
```

- [ ] **Step 8:** Run all phase-9 tests

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Middleware/`
Expected: PASS.

- [ ] **Step 9:** Lint + commit

Run: `make psalm && make phpcs`

```bash
git add packages/nexus-http/src/Middleware/ \
        packages/nexus-http/src/Event/RouteMatched.php \
        packages/nexus-http/tests/Unit/Middleware/
git -c commit.gpgsign=false commit -m "feat(http): add middleware pipeline + Router + ExceptionHandler

MiddlewarePipeline resolves class strings to instances via container or
new()-constructor, runs PSR-15 chain. RouterMiddleware dispatches,
attaches path params + per-request scope, runs route MWs, awaits
Future if returned, disposes scope in finally. ExceptionHandler
walks the mapper registry."
```

---

## Phase 10: HttpApp glue — Dsl/HttpApp + App/CompiledHttpApp

**Outcome:** Clean two-class design.
- `Monadial\Nexus\Http\Dsl\HttpApp` is the fluent builder. It holds mutable boot-time state (registries, route collections, middleware lists). It does NOT implement `RequestHandlerInterface`. Its terminal operation, `compile()`, returns a fresh `CompiledHttpApp`.
- `Monadial\Nexus\Http\App\CompiledHttpApp` is the immutable runtime artifact. It implements `RequestHandlerInterface`. Server adapters consume `CompiledHttpApp`. It owns the fully compiled middleware chain (assembled once at compile time, NEVER reassembled per request).

The `Dsl\` namespace holds every fluent-DSL class (`HttpApp`, `RouteBuilder`, `RouteGroup`, `ActorRegistration`). Runtime/data classes stay in their topical namespaces (`Routing\`, `Actor\`, `Handler\`, …).

**Files:**
- Create: `packages/nexus-http/src/Dsl/HttpApp.php`
- Create: `packages/nexus-http/src/Dsl/RouteBuilder.php`
- Create: `packages/nexus-http/src/Dsl/RouteGroup.php`
- Create: `packages/nexus-http/src/App/CompiledHttpApp.php`
- Create: `packages/nexus-http/tests/Unit/Dsl/HttpAppTest.php`

Note: `ActorRegistration` is also in `Dsl\` per the Phase 5 plan update — already accounted for there.

- [ ] **Step 1:** Implement `Dsl\RouteBuilder`

Create `packages/nexus-http/src/Dsl/RouteBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Closure;
use Monadial\Nexus\Http\Routing\Route;

/**
 * @psalm-api
 *
 * Fluent setter for one route. Mutating until HttpApp::compile() freezes
 * it into a Route value object.
 */
final class RouteBuilder
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly string|Closure $handler,
    ) {}

    public function middleware(string $class): self
    {
        $this->middleware[] = $class;
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function build(): Route
    {
        return new Route($this->method, $this->path, $this->handler, $this->middleware, $this->name);
    }
}
```

- [ ] **Step 2:** Implement `Dsl\RouteGroup`

Create `packages/nexus-http/src/Dsl/RouteGroup.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Closure;
use Monadial\Nexus\Http\Routing\Route;

/**
 * @psalm-api
 *
 * Group of routes sharing a prefix and middleware stack. Created via
 * HttpApp::group(); routes added here are committed back to the parent
 * collection with prefix + group MWs prepended.
 */
final class RouteGroup
{
    /** @var list<string> */
    private array $middleware = [];

    /** @var list<RouteBuilder> */
    private array $pending = [];

    public function __construct(private readonly string $prefix) {}

    public function middleware(string $class): self
    {
        $this->middleware[] = $class;
        return $this;
    }

    public function get(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('GET', $path, $handler);
    }

    public function post(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('POST', $path, $handler);
    }

    public function put(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('PUT', $path, $handler);
    }

    public function patch(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('PATCH', $path, $handler);
    }

    public function delete(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('DELETE', $path, $handler);
    }

    /** @return list<Route> */
    public function commit(): array
    {
        $out = [];
        foreach ($this->pending as $builder) {
            $route = $builder->build()->withPrefixedPath($this->prefix);
            if ($this->middleware !== []) {
                $route = new Route(
                    $route->method,
                    $route->path,
                    $route->handler,
                    [...$this->middleware, ...$route->middleware],
                    $route->name,
                );
            }
            $out[] = $route;
        }

        return $out;
    }

    private function register(string $method, string $path, string|Closure $handler): RouteBuilder
    {
        $builder = new RouteBuilder($method, $path, $handler);
        $this->pending[] = $builder;
        return $builder;
    }
}
```

- [ ] **Step 3:** Implement `Dsl\HttpApp`

Create `packages/nexus-http/src/Dsl/HttpApp.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistry;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Exception\DefaultMappers;
use Monadial\Nexus\Http\Exception\ExceptionMapperRegistry;
use Monadial\Nexus\Http\Handler\HandlerResolver;
use Monadial\Nexus\Http\Middleware\ExceptionHandlerMiddleware;
use Monadial\Nexus\Http\Middleware\MiddlewareInvoker;
use Monadial\Nexus\Http\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Http\Middleware\RouterMiddleware;
use Monadial\Nexus\Http\Routing\Dispatcher;
use Monadial\Nexus\Http\Routing\RouteCollection;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Fluent DSL for building an HTTP app. Mutable during construction.
 * Terminal operation `compile()` returns an immutable {@see CompiledHttpApp}
 * — server adapters consume that, not the DSL.
 *
 * HttpApp itself does NOT implement RequestHandlerInterface. The DSL builds,
 * the CompiledHttpApp serves.
 */
final class HttpApp
{
    private readonly ActorRegistry $registry;
    private readonly RouteCollection $routes;
    private readonly ExceptionMapperRegistry $mappers;

    /** @var list<RouteBuilder> */
    private array $pendingBuilders = [];

    /** @var list<string|MiddlewareInterface> */
    private array $globalMiddleware = [];

    /** @var list<Closure(ExceptionMapperRegistry): void> */
    private array $userExceptionRegistrations = [];

    private ErrorMode $errorMode = ErrorMode::Production;
    private bool $useDefaultExceptionHandler = true;
    private ?WorkerNode $workerNode = null;

    private function __construct(
        private readonly ActorSystem $system,
        private readonly ?ContainerInterface $container,
        private readonly ?EventDispatcherInterface $events,
        private readonly ?LoggerInterface $logger,
    ) {
        $this->registry = new ActorRegistry();
        $this->routes = new RouteCollection();
        $this->mappers = new ExceptionMapperRegistry();
    }

    public static function create(
        ActorSystem $system,
        ?ContainerInterface $container = null,
        ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self($system, $container, $events, $logger);
    }

    public function withWorkerNode(WorkerNode $node): self
    {
        $this->workerNode = $node;
        return $this;
    }

    // ─── Actors ────────────────────────────────────────────────────

    public function actor(string $name, Props $props): ActorRegistration
    {
        return $this->registry->register($name, $props, ActorMode::WorkerLocal);
    }

    public function perRequestActor(string $name, Props $props): ActorRegistration
    {
        return $this->registry->register($name, $props, ActorMode::PerRequest);
    }

    // ─── Routing ───────────────────────────────────────────────────

    public function get(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('GET', $path, $handler);
    }

    public function post(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('POST', $path, $handler);
    }

    public function put(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('PUT', $path, $handler);
    }

    public function patch(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('DELETE', $path, $handler);
    }

    /** @param Closure(RouteGroup): void $register */
    public function group(string $prefix, Closure $register): RouteGroup
    {
        $group = new RouteGroup($prefix);
        $register($group);

        foreach ($group->commit() as $route) {
            $this->routes->add($route);
        }

        return $group;
    }

    // ─── Middleware ────────────────────────────────────────────────

    public function middleware(string|MiddlewareInterface $middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    // ─── Errors ────────────────────────────────────────────────────

    /** @param Closure(Throwable, ServerRequestInterface): ResponseInterface $mapper */
    public function onException(string $exceptionClass, Closure $mapper): self
    {
        $this->userExceptionRegistrations[] = static fn(ExceptionMapperRegistry $r) => $r->register($exceptionClass, $mapper);
        return $this;
    }

    public function errorMode(ErrorMode $mode): self
    {
        $this->errorMode = $mode;
        return $this;
    }

    public function withoutDefaultExceptionHandler(): self
    {
        $this->useDefaultExceptionHandler = false;
        return $this;
    }

    // ─── Capability flags ──────────────────────────────────────────

    public function requiresPoolSingleton(): bool
    {
        foreach ($this->registry->freeze() as $entry) {
            if ($entry->mode === ActorMode::PoolSingleton) {
                return true;
            }
        }
        return false;
    }

    // ─── Compile ───────────────────────────────────────────────────

    /**
     * Freeze the DSL state into an immutable, ready-to-serve CompiledHttpApp.
     * Calling compile() multiple times yields independent CompiledHttpApp
     * instances reflecting the DSL state at each call.
     */
    public function compile(): CompiledHttpApp
    {
        // 1. Promote pending route builders BEFORE the dispatcher is built.
        foreach ($this->pendingBuilders as $builder) {
            $this->routes->add($builder->build());
        }
        $this->pendingBuilders = [];

        // 2. Resolve actor table.
        $entries = $this->registry->freeze();
        $table = ResolvedActorTable::build($entries, $this->system, $this->workerNode);

        // 3. Resolve handlers per route.
        $resolver = new HandlerResolver($table, $this->container);
        $routes = $this->routes->all();
        $handlersByKey = [];
        $routeMwsByKey = [];

        foreach ($routes as $route) {
            $key = $route->method . ':' . $route->path;
            $handlersByKey[$key] = $resolver->resolve($route->handler);
            $routeMwsByKey[$key] = $route->middleware;
        }

        // 4. Mappers — defaults first so user overrides win.
        $mappers = clone $this->mappers;
        if ($this->useDefaultExceptionHandler) {
            DefaultMappers::registerInto($mappers, $this->errorMode);
        }
        foreach ($this->userExceptionRegistrations as $apply) {
            $apply($mappers);
        }

        // 5. Build dispatcher + RouterMiddleware.
        $pipeline = new MiddlewarePipeline($this->container);
        $router = new RouterMiddleware(
            Dispatcher::build($routes),
            $handlersByKey,
            $routeMwsByKey,
            $pipeline,
            $this->system,
            $table,
            $this->events,
        );

        // 6. Compile the full middleware stack into ONE RequestHandlerInterface.
        $stack = [];
        if ($this->useDefaultExceptionHandler) {
            $stack[] = new ExceptionHandlerMiddleware($mappers, $this->logger);
        }
        foreach ($this->globalMiddleware as $mw) {
            $stack[] = $mw instanceof MiddlewareInterface ? $mw : $this->resolveMiddleware($mw);
        }
        $stack[] = $router;

        $tail = static fn(ServerRequestInterface $r): ResponseInterface =>
            throw new LogicException('RouterMiddleware did not produce a response');

        return new CompiledHttpApp(new MiddlewareInvoker($stack, $tail), $this->events);
    }

    private function registerRoute(string $method, string $path, string|Closure $handler): RouteBuilder
    {
        $builder = new RouteBuilder($method, $path, $handler);
        $this->pendingBuilders[] = $builder;
        return $builder;
    }

    private function resolveMiddleware(string $class): MiddlewareInterface
    {
        if ($this->container !== null && $this->container->has($class)) {
            /** @var MiddlewareInterface */
            return $this->container->get($class);
        }
        /** @var MiddlewareInterface */
        return new $class();
    }
}
```

- [ ] **Step 4:** Implement `App\CompiledHttpApp`

Create `packages/nexus-http/src/App/CompiledHttpApp.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\App;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Immutable, ready-to-serve HTTP app. Produced by {@see HttpApp::compile()}.
 * Implements PSR-15 RequestHandlerInterface — server adapters consume this.
 *
 * The internal handler chain (exception mw → globals → router) is compiled
 * once during construction; handle() invokes it directly with no per-request
 * stack assembly.
 *
 * The PSR-14 event hookup for RequestStarted / RequestCompleted is added in
 * Phase 13 (this class keeps the events reference but doesn't dispatch yet
 * at this phase).
 */
final readonly class CompiledHttpApp implements RequestHandlerInterface
{
    public function __construct(
        private RequestHandlerInterface $compiledHandler,
        private ?EventDispatcherInterface $events,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->compiledHandler->handle($request);
    }
}
```

- [ ] **Step 5:** Write integration-style HttpApp tests

Create `packages/nexus-http/tests/Unit/Dsl/HttpAppTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Dsl;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Dsl\RouteGroup;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(HttpApp::class)]
#[CoversClass(CompiledHttpApp::class)]
final class HttpAppTest extends TestCase
{
    #[Test]
    public function compile_returns_compiled_http_app(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);

        $compiled = $app->compile();

        self::assertInstanceOf(CompiledHttpApp::class, $compiled);
    }

    #[Test]
    public function get_route_with_closure_handler_dispatches(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/hello', static fn(): ResponseInterface => Response::ok())
            ->name('hello');

        $response = $app->compile()->handle(new ServerRequest('GET', '/hello'));
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function unknown_route_returns_404(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/hello', static fn(): ResponseInterface => Response::ok());

        $response = $app->compile()->handle(new ServerRequest('GET', '/missing'));
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function group_prefix_and_middleware_apply(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->group('/api', static function (RouteGroup $g): void {
            $g->get('/ping', static fn(): ResponseInterface => Response::ok());
        });

        $response = $app->compile()->handle(new ServerRequest('GET', '/api/ping'));
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function requires_pool_singleton_reflects_registry(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);

        self::assertFalse($app->requiresPoolSingleton());
    }
}
```

- [ ] **Step 6:** Run

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Dsl/HttpAppTest.php`
Expected: PASS.

- [ ] **Step 7:** Lint + commit

Run: `make psalm && make phpcs`

```bash
git add packages/nexus-http/src/Dsl/ \
        packages/nexus-http/src/App/CompiledHttpApp.php \
        packages/nexus-http/tests/Unit/Dsl/
git -c commit.gpgsign=false commit -m "feat(http): add Dsl\HttpApp + App\CompiledHttpApp

Clean two-class design: Dsl\HttpApp is the mutable fluent builder
(actors, routes, middleware, error mappers). Its compile() returns
an immutable App\CompiledHttpApp that implements
RequestHandlerInterface. Server adapters consume the compiled app.

The middleware stack is fully assembled inside compile() into a
single RequestHandlerInterface — no per-request reassembly. DSL
classes (HttpApp, RouteBuilder, RouteGroup, ActorRegistration)
live under Monadial\Nexus\Http\Dsl\."
```

---

## Phase 11: Attribute discovery — `discover()`

**Outcome:** `$app->discover(string $directory)` walks the directory and finds classes with `#[Route]` attributes. Adds them to the collection at compile time. Uses Composer's classmap when available, otherwise a `RecursiveDirectoryIterator`.

**Files:**
- Create: `packages/nexus-http/src/Discovery/RouteDiscoverer.php`
- Modify: `packages/nexus-http/src/App/HttpApp.php` (add `discover(string)` method)
- Create: `packages/nexus-http/tests/Unit/Discovery/RouteDiscovererTest.php`
- Create: `packages/nexus-http/tests/Unit/Discovery/Fixtures/DiscoveredAction.php`

- [ ] **Step 1:** Implement `RouteDiscoverer`

Create `packages/nexus-http/src/Discovery/RouteDiscoverer.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Discovery;

use Monadial\Nexus\Http\Routing\Attribute\Route as RouteAttribute;
use Monadial\Nexus\Http\Routing\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * @psalm-api
 *
 * Finds all classes with #[Route] attributes under a directory. Returns
 * Route value objects ready to add to the collection.
 */
final class RouteDiscoverer
{
    /** @return list<Route> */
    public function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $routes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classFromFile($file->getPathname());
            if ($class === null || !class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            foreach ($reflection->getAttributes(RouteAttribute::class) as $attr) {
                $routeAttr = $attr->newInstance();
                $routes[] = new Route(
                    $routeAttr->method,
                    $routeAttr->path,
                    $class,
                    $routeAttr->middleware,
                    $routeAttr->name,
                );
            }
        }

        return $routes;
    }

    private function classFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        if (preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $contents, $m) === 1) {
            $namespace = $m[1];
        }
        if (preg_match('/(?:^|\s)(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $m) === 1) {
            $class = $m[1];
        }

        if ($class === null) {
            return null;
        }
        return $namespace === null ? $class : $namespace . '\\' . $class;
    }
}
```

- [ ] **Step 2:** Add `discover()` to `HttpApp`

Edit `packages/nexus-http/src/App/HttpApp.php`. Add a `use` import at the top of the file (alongside the others):

```php
use Monadial\Nexus\Http\Discovery\RouteDiscoverer;
```

Add this property to the class body (near the other `private array` fields):

```php
    /** @var list<string> */
    private array $discoveryDirs = [];
```

Add this method (alongside `discover` belongs in the routing block):

```php
    public function discover(string $directory): self
    {
        $this->discoveryDirs[] = $directory;
        return $this;
    }
```

In `compile()`, BEFORE the "Commit pending route builders" step, add:

```php
        // Discovered routes
        $discoverer = new RouteDiscoverer();
        foreach ($this->discoveryDirs as $dir) {
            foreach ($discoverer->discover($dir) as $route) {
                $this->routes->add($route);
            }
        }
```

- [ ] **Step 3:** Create fixture

Create `packages/nexus-http/tests/Unit/Discovery/Fixtures/DiscoveredAction.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Discovery\Fixtures;

use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Routing\Attribute\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Route('GET', '/discovered/{id}', name: 'discovered.show', middleware: ['App\\Mw'])]
final class DiscoveredAction
{
    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}
```

- [ ] **Step 4:** Write tests

Create `packages/nexus-http/tests/Unit/Discovery/RouteDiscovererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Discovery;

use Monadial\Nexus\Http\Discovery\RouteDiscoverer;
use Monadial\Nexus\Http\Tests\Unit\Discovery\Fixtures\DiscoveredAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteDiscoverer::class)]
final class RouteDiscovererTest extends TestCase
{
    #[Test]
    public function discovers_route_attribute_on_action_class(): void
    {
        $discoverer = new RouteDiscoverer();

        $routes = $discoverer->discover(__DIR__ . '/Fixtures');

        self::assertCount(1, $routes);
        self::assertSame('GET', $routes[0]->method);
        self::assertSame('/discovered/{id}', $routes[0]->path);
        self::assertSame(DiscoveredAction::class, $routes[0]->handler);
        self::assertSame(['App\\Mw'], $routes[0]->middleware);
        self::assertSame('discovered.show', $routes[0]->name);
    }

    #[Test]
    public function nonexistent_directory_returns_empty(): void
    {
        $discoverer = new RouteDiscoverer();
        self::assertSame([], $discoverer->discover('/does/not/exist'));
    }
}
```

- [ ] **Step 5:** Run tests

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Discovery/`
Expected: PASS.

- [ ] **Step 6:** Lint + commit

Run: `make psalm && make phpcs`

```bash
git add packages/nexus-http/src/Discovery/ \
        packages/nexus-http/src/App/HttpApp.php \
        packages/nexus-http/tests/Unit/Discovery/
git -c commit.gpgsign=false commit -m "feat(http): add attribute-based route discovery

\$app->discover(\$dir) walks the directory at compile() time, finds
classes with #[Route] attributes, and adds matching routes to the
collection. Uses a regex-based file scan to extract namespace+class,
then ReflectionClass for the attribute(s)."
```

---

## Phase 12: Route caching — PSR-16 cache adapter

**Outcome:** `$app->withRouteCache(CacheInterface $cache, string $key = 'nexus.http.routes')` enables PSR-16 (SimpleCache) cache-backed route metadata caching. On compile: try the cache key; if hit, hydrate routes from the cached payload; if miss, persist the freshly-built routes. Closure routes are skipped from caching and re-added from the in-memory collection on boot. FastRoute's regex tree is rebuilt from the cached routes on each boot (sub-millisecond for typical route counts).

Why PSR-16: lets the user plug in any cache backend (APCu, Redis, Memcached, file system) via existing PSR-16 adapters (`symfony/cache`, `cache/cache`, etc.) — no framework-specific file format, no proprietary serialization.

**Files:**
- Modify: `packages/nexus-http/composer.json` — add `psr/simple-cache: ^3.0`
- Modify: `composer.json` (root) — add `psr/simple-cache: ^3.0`
- Create: `packages/nexus-http/src/Cache/RouteCachePersister.php`
- Modify: `packages/nexus-http/src/App/HttpApp.php` — `withRouteCache(CacheInterface, ?string)`
- Create: `packages/nexus-http/tests/Unit/Cache/RouteCachePersisterTest.php`

Note: `Dispatcher::buildCached` is NOT added (FastRoute file caching is replaced by PSR-16 metadata caching). The base `Dispatcher::build` is used unconditionally.

- [ ] **Step 1:** Add `psr/simple-cache` to package + root composer.json

Edit `packages/nexus-http/composer.json` — add to `require` (alphabetical):
```json
"psr/simple-cache": "^3.0",
```

Edit root `composer.json` — add to `require` (alphabetical):
```json
"psr/simple-cache": "^3.0",
```

Run: `make install` to install the new dep.
Expected: composer install succeeds.

- [ ] **Step 2:** Implement `RouteCachePersister`

Create `packages/nexus-http/src/Cache/RouteCachePersister.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Cache;

use Closure;
use Monadial\Nexus\Http\Routing\Route;
use Psr\SimpleCache\CacheInterface;

/**
 * @psalm-api
 *
 * PSR-16-backed persistence of route metadata. Closure handlers are skipped
 * from the cache (they can't be serialized) — callers re-add them from the
 * in-memory collection after a hit.
 *
 * Cache payload shape: list of [method, path, handler, middleware[], name]
 * arrays. var_export-safe so any PSR-16 backend can serialize it.
 */
final class RouteCachePersister
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $key,
    ) {}

    /**
     * Returns the cached routes, or null on miss.
     *
     * @return list<Route>|null
     */
    public function load(): ?array
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: list<string>, 4: ?string}>|null $payload */
        $payload = $this->cache->get($this->key);
        if ($payload === null) {
            return null;
        }

        $routes = [];
        foreach ($payload as $row) {
            $routes[] = new Route($row[0], $row[1], $row[2], $row[3], $row[4]);
        }

        return $routes;
    }

    /**
     * Persist the string-handler subset of the given routes.
     *
     * @param list<Route> $routes
     */
    public function save(array $routes): void
    {
        $payload = [];
        foreach ($routes as $route) {
            if (!is_string($route->handler)) {
                continue;
            }
            $payload[] = [
                $route->method,
                $route->path,
                $route->handler,
                $route->middleware,
                $route->name,
            ];
        }

        $this->cache->set($this->key, $payload);
    }

    public function clear(): void
    {
        $this->cache->delete($this->key);
    }
}
```

- [ ] **Step 3:** Wire `withRouteCache` into `HttpApp`

Edit `packages/nexus-http/src/App/HttpApp.php`. Add the import at the top alongside others:

```php
use Monadial\Nexus\Http\Cache\RouteCachePersister;
use Psr\SimpleCache\CacheInterface;
```

Add the fields (alongside other private fields):

```php
    private ?CacheInterface $routeCache = null;
    private string $routeCacheKey = 'nexus.http.routes';
```

Replace any prior file-based `withRouteCache` / `clearRouteCache` with these methods:

```php
    public function withRouteCache(CacheInterface $cache, ?string $key = null): self
    {
        $this->routeCache = $cache;
        if ($key !== null) {
            $this->routeCacheKey = $key;
        }
        return $this;
    }

    public function clearRouteCache(): void
    {
        if ($this->routeCache !== null) {
            (new RouteCachePersister($this->routeCache, $this->routeCacheKey))->clear();
        }
    }
```

In `compile()`, after the pending-builder commit and discovery walk (still BEFORE the actor table resolution), add the cache hit-or-fill block:

```php
        $routeList = $this->routes->all();

        if ($this->routeCache !== null) {
            $persister = new RouteCachePersister($this->routeCache, $this->routeCacheKey);
            $cached = $persister->load();
            if ($cached !== null) {
                // Re-add closure-handler routes from the live collection
                // (they're skipped from cache).
                $closureRoutes = [];
                foreach ($routeList as $route) {
                    if (!is_string($route->handler)) {
                        $closureRoutes[] = $route;
                    }
                }
                $routeList = [...$cached, ...$closureRoutes];
            } else {
                $persister->save($routeList);
            }
        }
```

And use `$routeList` (the local) for the rest of the compile flow — dispatcher and handler resolution iterate `$routeList` instead of `$this->routes->all()`.

- [ ] **Step 4:** Write tests using `symfony/cache` ArrayAdapter

`symfony/cache` is already in root `require-dev`. We use its `Psr16Adapter` wrapping an `ArrayAdapter` (in-memory) for tests.

Create `packages/nexus-http/tests/Unit/Cache/RouteCachePersisterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Cache;

use Monadial\Nexus\Http\Cache\RouteCachePersister;
use Monadial\Nexus\Http\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

#[CoversClass(RouteCachePersister::class)]
final class RouteCachePersisterTest extends TestCase
{
    private function newCache(): \Psr\SimpleCache\CacheInterface
    {
        return new Psr16Cache(new ArrayAdapter());
    }

    #[Test]
    public function load_returns_null_on_cold_cache(): void
    {
        $persister = new RouteCachePersister($this->newCache(), 'routes');

        self::assertNull($persister->load());
    }

    #[Test]
    public function save_then_load_round_trips_fields(): void
    {
        $cache = $this->newCache();
        $persister = new RouteCachePersister($cache, 'routes');
        $routes = [
            new Route('GET', '/a/{id}', 'App\\A', ['M1'], 'a.show'),
            new Route('POST', '/b', 'App\\B', [], null),
        ];

        $persister->save($routes);
        $loaded = $persister->load();

        self::assertNotNull($loaded);
        self::assertCount(2, $loaded);
        self::assertSame('/a/{id}', $loaded[0]->path);
        self::assertSame('a.show', $loaded[0]->name);
        self::assertSame(['M1'], $loaded[0]->middleware);
        self::assertSame('App\\B', $loaded[1]->handler);
    }

    #[Test]
    public function save_skips_closure_handlers(): void
    {
        $cache = $this->newCache();
        $persister = new RouteCachePersister($cache, 'routes');
        $routes = [
            new Route('GET', '/closure', static fn() => null, [], null),
            new Route('GET', '/string', 'App\\X', [], null),
        ];

        $persister->save($routes);
        $loaded = $persister->load();

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded);
        self::assertSame('/string', $loaded[0]->path);
    }

    #[Test]
    public function clear_evicts_the_cached_routes(): void
    {
        $cache = $this->newCache();
        $persister = new RouteCachePersister($cache, 'routes');
        $persister->save([new Route('GET', '/a', 'App\\A', [], null)]);

        $persister->clear();

        self::assertNull($persister->load());
    }
}
```

- [ ] **Step 5:** Run tests + lint

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Cache/`
Then: `make psalm && make phpcs`
Expected: clean.

- [ ] **Step 6:** Commit

```bash
git add packages/nexus-http/composer.json \
        composer.json \
        composer.lock \
        packages/nexus-http/src/Cache/ \
        packages/nexus-http/src/App/HttpApp.php \
        packages/nexus-http/tests/Unit/Cache/
git -c commit.gpgsign=false commit -m "feat(http): add PSR-16 route cache adapter

withRouteCache(CacheInterface, ?key) replaces the file-path based
caching. RouteCachePersister wraps a PSR-16 store; closure handlers
are skipped from the payload and re-added from the in-memory
collection on boot. clearRouteCache() evicts the cached key. Backends
(APCu, Redis, file system, …) plug in via existing PSR-16 adapters."
```

---

## Phase 13: PSR-14 events — `RequestStarted`, `RequestCompleted`

**Outcome:** Two additional events fire around the pipeline. Dispatch is opt-in via the `EventDispatcher` supplied to `HttpApp::create()`. Cost when null: one identity check per request.

**Files:**
- Create: `packages/nexus-http/src/Event/RequestStarted.php`
- Create: `packages/nexus-http/src/Event/RequestCompleted.php`
- Modify: `packages/nexus-http/src/App/CompiledHttpApp.php` (wrap handle() with events)
- Create: `packages/nexus-http/tests/Unit/Event/EventDispatchTest.php`

- [ ] **Step 1:** Implement event classes

`packages/nexus-http/src/Event/RequestStarted.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Event;

use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class RequestStarted
{
    public function __construct(
        public ServerRequestInterface $request,
        public int $startNanos,
    ) {}
}
```

`packages/nexus-http/src/Event/RequestCompleted.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Event;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class RequestCompleted
{
    public function __construct(
        public ServerRequestInterface $request,
        public ResponseInterface $response,
        public int $durationNanos,
    ) {}
}
```

- [ ] **Step 2:** Wrap `CompiledHttpApp::handle()` with event emission

Edit `packages/nexus-http/src/App/CompiledHttpApp.php` to emit `RequestStarted`/`RequestCompleted` around the compiled chain. The middleware stack itself is already assembled — events are a thin outer wrapper around its invocation.

```php
    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->events === null) {
            return $this->compiledHandler->handle($request);
        }

        $start = hrtime(true);
        $this->events->dispatch(new \Monadial\Nexus\Http\Event\RequestStarted($request, $start));

        $response = $this->compiledHandler->handle($request);

        $this->events->dispatch(
            new \Monadial\Nexus\Http\Event\RequestCompleted($request, $response, hrtime(true) - $start),
        );

        return $response;
    }
```

The explicit null-check at the top is the cheapest possible fast path when no `EventDispatcher` is wired — one identity check, then straight through to the compiled handler.

- [ ] **Step 3:** Write tests

Create `packages/nexus-http/tests/Unit/Event/EventDispatchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Event;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Event\RequestCompleted;
use Monadial\Nexus\Http\Event\RequestStarted;
use Monadial\Nexus\Http\Event\RouteMatched;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;

final class _RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    #[Override]
    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        return $event;
    }
}

#[CoversClass(RequestStarted::class)]
#[CoversClass(RequestCompleted::class)]
#[CoversClass(RouteMatched::class)]
final class EventDispatchTest extends TestCase
{
    #[Test]
    public function emits_three_events_in_order(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $dispatcher = new _RecordingDispatcher();
        $app = HttpApp::create($system, events: $dispatcher);
        $app->get('/x', static fn(): ResponseInterface => Response::ok());

        $app->compile()->handle(new ServerRequest('GET', '/x'));

        self::assertCount(3, $dispatcher->events);
        self::assertInstanceOf(RequestStarted::class, $dispatcher->events[0]);
        self::assertInstanceOf(RouteMatched::class, $dispatcher->events[1]);
        self::assertInstanceOf(RequestCompleted::class, $dispatcher->events[2]);
    }
}
```

- [ ] **Step 4:** Run + commit

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-http/tests/Unit/Event/`
Then: `make psalm && make phpcs`

```bash
git add packages/nexus-http/src/Event/ \
        packages/nexus-http/src/App/CompiledHttpApp.php \
        packages/nexus-http/tests/Unit/Event/
git -c commit.gpgsign=false commit -m "feat(http): emit PSR-14 events around the compiled pipeline

CompiledHttpApp wraps event emission around the compiled handler call:
RequestStarted at entry, RouteMatched after dispatch (from
RouterMiddleware), RequestCompleted at exit with nano-precision
duration. Dispatcher is optional — null check skips emission entirely
and goes straight through to the compiled handler."
```

---

## Phase 14: Server adapter interface + contract test

**Outcome:** `HttpServerAdapter` interface in `Server/`. Abstract contract test that server packages extend to verify request handling, response writing, streaming chunk semantics, graceful shutdown.

**Files:**
- Create: `packages/nexus-http/src/Server/HttpServerAdapter.php`
- Create: `packages/nexus-http/tests/Contract/HttpServerAdapterContractTest.php`

- [ ] **Step 1:** Implement the interface

Create `packages/nexus-http/src/Server/HttpServerAdapter.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Server adapter contract. Implemented by concrete server packages
 * (nexus-http-server-swoole, hypothetical nexus-http-server-fiber, ...).
 *
 * Implementations MUST:
 * - Build PSR-7 ServerRequests from incoming HTTP requests
 * - Call $app->handle($request) for each request
 * - For ResponseInterface bodies that are streaming (read() returns chunks),
 *   call body->read() in a loop and flush per chunk to the wire — do NOT
 *   buffer the full body for streaming responses
 */
interface HttpServerAdapter
{
    /** Block and serve until shutdown is called. */
    public function serve(RequestHandlerInterface $app): void;

    /** Drain in-flight requests within the timeout, then stop. */
    public function shutdown(Duration $timeout): void;
}
```

- [ ] **Step 2:** Implement contract test base

Create `packages/nexus-http/tests/Contract/HttpServerAdapterContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Contract;

use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\HttpServerAdapter;
use Monadial\Nexus\Runtime\Duration;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Concrete server adapter packages extend this and implement createAdapter()
 * + bind/connect helpers for their server type. The shared tests below
 * verify the adapter honours the contract.
 */
abstract class HttpServerAdapterContractTest extends TestCase
{
    abstract protected function createAdapter(): HttpServerAdapter;

    /** Returns (host, port) the adapter binds to. */
    abstract protected function bindAddress(): array;

    /** Send an HTTP request and return the raw body string. */
    abstract protected function sendGet(string $path): string;

    #[Test]
    public function adapter_handles_request_and_writes_response(): void
    {
        $app = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return Response::ok()->withBody(\Nyholm\Psr7\Stream::create('hello'));
            }
        };

        $adapter = $this->createAdapter();
        $adapter->serve($app);

        $body = $this->sendGet('/');
        self::assertSame('hello', $body);

        $adapter->shutdown(Duration::seconds(1));
    }
}
```

- [ ] **Step 3:** Lint + commit

Run: `make psalm && make phpcs`

```bash
git add packages/nexus-http/src/Server/ packages/nexus-http/tests/Contract/
git -c commit.gpgsign=false commit -m "feat(http): add HttpServerAdapter interface + contract test

Single-method contract: serve(\$app) blocks until shutdown(\$timeout).
Streaming-write semantics documented on the interface — adapters MUST
flush per StreamInterface::read() chunk for SSE/NDJSON to work.
Abstract contract test base for concrete server packages to extend."
```

---

## Phase 15: Integration tests — full pipeline

**Outcome:** Integration tests at `tests/Integration/Http/` drive `HttpApp::handle()` directly with real actors. Covers all three lifecycle modes, Future-returning handlers, streaming responses, exception mapping, group middleware.

**Files:**
- Create: `tests/Integration/Http/HttpAppIntegrationTest.php`
- Create: `tests/Integration/Http/FutureHandlerTest.php`
- Create: `tests/Integration/Http/StreamingResponseIntegrationTest.php`
- Create: `tests/Integration/Http/ExceptionMappingTest.php`
- Modify: `phpunit.xml` — add new testsuite `integration-http`
- Modify: `composer.json` (root) — add `"Monadial\\Nexus\\Tests\\Integration\\Http\\"` mapping (already covered by the existing `Monadial\Nexus\Tests\Integration\` PSR-4 mapping)
- Modify: `Makefile` — add `test-http` target

- [ ] **Step 1:** Add testsuite to `phpunit.xml`

Add after `integration-step`:
```xml
        <testsuite name="integration-http">
            <directory>tests/Integration/Http</directory>
        </testsuite>
```

- [ ] **Step 2:** Add `test-http` to `Makefile`

Append:
```makefile
test-http: ## HTTP integration tests
	$(DC) vendor/bin/phpunit --testsuite=integration-http
```

- [ ] **Step 3:** Write the core integration test (worker-local + per-request via tell only)

The integration test uses fire-and-forget `tell()` to avoid coupling to `ask`/`reply` conventions. A test-double behavior records each message into a shared array via closure capture; the handler reads that array.

Create `tests/Integration/Http/HttpAppIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Http;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class _RecordMessage { public function __construct(public string $value) {} }

#[CoversNothing]
final class HttpAppIntegrationTest extends TestCase
{
    #[Test]
    public function worker_local_actor_receives_tell_from_handler(): void
    {
        $received = [];
        $recorderBehavior = Behavior::receive(static function (
            ActorContext $ctx,
            object $msg,
        ) use (&$received) {
            if ($msg instanceof _RecordMessage) {
                $received[] = $msg->value;
            }
            return Behavior::same();
        });

        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->actor('recorder', Props::fromBehavior($recorderBehavior))->workerLocal();
        $app->post('/record/{value}', static function (
            ServerRequestInterface $r,
            #[FromActor('recorder')] ActorRef $recorder,
        ): ResponseInterface {
            $value = (string) $r->getAttribute('value');
            $recorder->tell(new _RecordMessage($value));
            return JsonResponse::ok(['recorded' => $value]);
        });

        $response = $app->compile()->handle(new ServerRequest('POST', '/record/hello'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"recorded":"hello"}', (string) $response->getBody());
        self::assertSame(['hello'], $received);
    }

    #[Test]
    public function per_request_actor_is_spawned_lazily_and_disposed(): void
    {
        $spawned = 0;
        $sagaBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$spawned) {
            $spawned++;
            return Behavior::same();
        });

        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->perRequestActor('saga', Props::fromBehavior($sagaBehavior));
        $app->post('/run', static function (
            ServerRequestInterface $r,
            #[FromActor('saga')] ActorRef $saga,
        ): ResponseInterface {
            $saga->tell(new _RecordMessage('go'));
            return JsonResponse::ok(['ran' => true]);
        });

        $response = $app->compile()->handle(new ServerRequest('POST', '/run'));

        self::assertSame(200, $response->getStatusCode());
        // The actor was spawned at least once and received the tell.
        self::assertGreaterThanOrEqual(1, $spawned);
    }
}
```

- [ ] **Step 4:** Write Future-returning handler test

Create `tests/Integration/Http/FutureHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureResult;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

#[CoversNothing]
final class FutureHandlerTest extends TestCase
{
    #[Test]
    public function future_returning_handler_is_awaited(): void
    {
        $a = new stdClass(); $a->name = 'a';
        $b = new stdClass(); $b->name = 'b';

        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->get('/fan-out', static fn(ServerRequestInterface $r): Future =>
            Future::all([
                'a' => Future::resolved($a),
                'b' => Future::resolved($b),
            ])->map(static fn(FutureResult $r): ResponseInterface =>
                JsonResponse::ok([
                    'a' => $r->values['a']->name,
                    'b' => $r->values['b']->name,
                ])));

        $response = $app->compile()->handle(new ServerRequest('GET', '/fan-out'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"a":"a","b":"b"}', (string) $response->getBody());
    }
}
```

- [ ] **Step 5:** Write streaming test

Create `tests/Integration/Http/StreamingResponseIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\StreamingResponse;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversNothing]
final class StreamingResponseIntegrationTest extends TestCase
{
    #[Test]
    public function ndjson_streams_through_handler(): void
    {
        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->get('/items', static fn(ServerRequestInterface $r): ResponseInterface =>
            StreamingResponse::ndjson([['id' => 1], ['id' => 2]]));

        $response = $app->compile()->handle(new ServerRequest('GET', '/items'));

        self::assertSame('application/x-ndjson', $response->getHeaderLine('Content-Type'));
        $body = $response->getBody();
        $first = $body->read(1024);
        $second = $body->read(1024);
        self::assertSame("{\"id\":1}\n", $first);
        self::assertSame("{\"id\":2}\n", $second);
    }
}
```

- [ ] **Step 6:** Write exception mapping test

Create `tests/Integration/Http/ExceptionMappingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Exception\HttpException;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

#[CoversNothing]
final class ExceptionMappingTest extends TestCase
{
    #[Test]
    public function unknown_route_returns_404(): void
    {
        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);

        $response = $app->compile()->handle(new ServerRequest('GET', '/missing'));
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function http_exception_thrown_in_handler_maps_to_status(): void
    {
        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->get('/forbidden', static fn(ServerRequestInterface $r): ResponseInterface =>
            throw HttpException::forbidden());

        $response = $app->compile()->handle(new ServerRequest('GET', '/forbidden'));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function generic_throwable_maps_to_500_in_production_mode(): void
    {
        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->get('/boom', static fn(): ResponseInterface => throw new RuntimeException('boom'));

        $response = $app->compile()->handle(new ServerRequest('GET', '/boom'));
        self::assertSame(500, $response->getStatusCode());
    }
}
```

- [ ] **Step 7:** Run the integration suite

Run: `make test-http`
Expected: All tests PASS.

- [ ] **Step 8:** Commit

```bash
git add tests/Integration/Http/ phpunit.xml Makefile
git -c commit.gpgsign=false commit -m "test(http): add integration suite for HttpApp pipeline

Covers worker-local actor injection, Future-returning handlers
(Future::all + map), streaming NDJSON response chunked through PSR-7
body reads, exception mapping (route not found, HttpException,
generic Throwable in production mode). 'make test-http' wired up."
```

---

## Phase 16: Performance baseline

**Outcome:** Micro-benchmark dispatching N requests against a 50-route table, asserting P99 stays under a target. Documents the hot-path budget claim from spec section 6.

**Files:**
- Create: `tests/Performance/Http/RouteDispatchBenchmarkTest.php`

- [ ] **Step 1:** Write the benchmark

Create `tests/Performance/Http/RouteDispatchBenchmarkTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversNothing]
final class RouteDispatchBenchmarkTest extends TestCase
{
    #[Test]
    public function dispatches_50_route_table_in_acceptable_time(): void
    {
        $system = ActorSystem::create('bench', new FiberRuntime());
        $app = HttpApp::create($system);

        for ($i = 0; $i < 50; $i++) {
            $app->get("/route-{$i}/{id}", static fn(): ResponseInterface => Response::ok());
        }

        $app->compile();
        $request = new ServerRequest('GET', '/route-25/42');

        $samples = [];
        $warmup = 100;
        $n = 10_000;
        for ($i = 0; $i < $warmup; $i++) {
            $app->handle($request);
        }
        for ($i = 0; $i < $n; $i++) {
            $start = hrtime(true);
            $app->handle($request);
            $samples[] = hrtime(true) - $start;
        }

        sort($samples);
        $p99 = $samples[(int) floor($n * 0.99)];

        // 1ms per request P99 is a generous initial budget; tighten as the
        // hot path matures. The threshold is a regression guard, not a target.
        self::assertLessThan(1_000_000, $p99, "P99 = {$p99}ns");
    }
}
```

- [ ] **Step 2:** Run

Run: `docker compose exec php vendor/bin/phpunit --testsuite=performance --filter=RouteDispatchBenchmark`
Expected: PASS. Note the actual P99 in the test output.

- [ ] **Step 3:** Commit

```bash
git add tests/Performance/Http/
git -c commit.gpgsign=false commit -m "test(http): add 50-route dispatch micro-benchmark

10K dispatches against /route-N/{id} routes; P99 asserted under 1ms
as a regression guard. Hot path is reflection-free after compile()."
```

---

## Phase 17: README + spec update referencing real API

**Outcome:** `packages/nexus-http/README.md` expanded with quickstart, lifecycle modes, async examples. The design spec gets a small clarification note about the real `ask` signature.

**Files:**
- Modify: `packages/nexus-http/README.md`
- Modify: `docs/superpowers/specs/2026-06-10-nexus-http-core-design.md` (one clarification block)

- [ ] **Step 1:** Expand README

Replace `packages/nexus-http/README.md` with:

```markdown
# nexus-http

PSR-15 HTTP framework on the Nexus actor system. Compile-time pipeline,
actor injection via attributes, three lifecycle modes, streaming responses.

## Install

```bash
composer require nexus-actors/http
```

## Quickstart

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$system = ActorSystem::create('my-app', new FiberRuntime());

$app = HttpApp::create($system)
    ->get('/hello', static fn() => JsonResponse::ok(['msg' => 'hello']))
    ->build();

// Hand to a server adapter (e.g. nexus-http-server-swoole)
$server->serve($app->compile());
```

## Actor injection

```php
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;

$app->actor('user-store', $userStoreProps)->workerLocal();
$app->perRequestActor('order-saga', $sagaProps);

$app->get('/users/{id}', static fn(
    ServerRequestInterface $r,
    #[FromActor('user-store')] ActorRef $store,
) => /* use $store->ask(...) */);
```

## Lifecycle modes

| Mode | Where it lives | Use for |
|---|---|---|
| `poolSingleton()` | One per worker pool (hash-routed) | Stateful aggregates, gateways |
| `workerLocal()` (default) | One per worker thread | Metric collectors, caches |
| `perRequestActor()` | Spawned per request, stopped at end | Per-request sagas, behavior switching |

See `docs/superpowers/specs/2026-06-10-nexus-http-core-design.md` for the
full design.
```

- [ ] **Step 2:** Add clarification block to the design spec

Edit `docs/superpowers/specs/2026-06-10-nexus-http-core-design.md`. Append after the "## 18. Open questions" section a new section:

```markdown
## 19. API clarifications (post-spec, during implementation)

- **`ActorRef::ask`** returns `Future<R>` directly. The synchronous form is `->ask(...)->await()`. The spec's earlier handler examples used `askFuture` as a hypothetical separate method; in practice `ask` *is* what would have been `askFuture`. All DSL examples below use `->ask($msg, $timeout)->await()` for the sync path and `->ask($msg, $timeout)` to keep the `Future` open.
- **Namespace** is `Monadial\Nexus\Http\` to match the rest of the monorepo.
- **`Future` extensions added in implementation:** `Future::all(array): Future<FutureResult>`, `Future::resolved(object): Future`, `Future::failed(FutureException): Future` were added to `nexus-runtime` to enable fan-out composition. `recover`, `race`, `withTimeout` remain deferred.
```

- [ ] **Step 3:** Commit

```bash
git add packages/nexus-http/README.md \
        docs/superpowers/specs/2026-06-10-nexus-http-core-design.md
git -c commit.gpgsign=false commit -m "docs(http): README quickstart + spec clarifications

README covers install, quickstart, actor injection, lifecycle modes.
Spec gains a Section 19 clarifying ask returns Future directly,
the namespace, and the Future combinators we added during build."
```

---

## Phase 18: Verification + handoff

**Outcome:** Whole suite green, all checks pass, package ready for the eventual `nexus-http-server-swoole` package.

- [ ] **Step 1:** Run full lint matrix

Run: `make psalm && make phpcs && make cs`
Expected: Clean across the board.

- [ ] **Step 2:** Run all unit + http integration tests

Run: `make test-unit && make test-http`
Expected: All PASS, total time noted.

- [ ] **Step 3:** Run deptrac to verify the dependency graph

Run: `docker compose exec php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse`
Expected: No violations. `Monadial\Nexus\Http\*` may depend only on `Core` + `Runtime` + PSR + WorkerPool (read-only — only for `WorkerNode` type).

- [ ] **Step 4:** Final commit if anything changed

If lint/deptrac surfaced any drift, fix inline, then:

```bash
git add -A
git -c commit.gpgsign=false commit -m "chore(http): final lint and deptrac sweep"
```

- [ ] **Step 5:** Tag readiness

```bash
git log --oneline | head -20
```

Verify the phase commits are present and chronological.

---

## What's NOT in this plan (deferred to follow-up specs)

| Item | Where it goes |
|---|---|
| `nexus-http-server-swoole` thread-based Swoole server | Separate spec + plan; consumes this package's `HttpServerAdapter` + `HttpApp` |
| `nexus-http-client` PSR-18 + Future-returning client | Separate spec + plan |
| `nexus-dbal-async` coroutine-safe DBAL | Separate spec + plan |
| Pool-of-N actor routing mode | v2 of nexus-http |
| Request-wide deadline context propagation | v2 of nexus-http |
| WebSocket support | `nexus-websocket` (separate package) |
| OpenAPI generation from `#[Route]` | `nexus-http-openapi` (separate package) |
| Future combinators `race`, `recover`, `withTimeout` | Future spec for `nexus-runtime` |
| `Psalm` rule `FromActorReferencesRegisteredActorRule` | Separate small task in `nexus-psalm` (mentioned in spec § 15) |

