# Nexus HTTP Server WebSocket Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the dual-builder Swoole HTTP/WebSocket DSL with a unified, runtime-agnostic `Application`/`WsApplication` decorator hierarchy living in a new `nexus-http-ws` package, while shrinking both Swoole runners (`nexus-http-server-swoole`, `nexus-http-server-swoole-threads`) to Swoole glue.

**Architecture:** Three packages — `nexus-http-ws` owns DSL, dispatcher, router, connection table, channel-actor registry, base classes, attributes, and exceptions. Both runner packages depend on `nexus-http-ws`. Pre-1.0 delete-and-rewrite migration; no compat shims. Runners accept the `CompiledApplication` interface and gate WS event wiring on `hasWebSocketRoutes()`.

**Tech Stack:** PHP 8.5, Swoole 6.0+, PSR-7/11/14/15, FastRoute, PHPUnit 13, Psalm L1, Deptrac, GrumPHP, Docker for everything.

**Spec:** `docs/superpowers/specs/2026-06-12-nexus-http-server-websocket-redesign.md`

**Branch:** `feat/nexus-http` (continue on this branch — no new worktree).

**Commit policy:** No `Co-Authored-By: Claude`. Use `-c commit.gpgsign=false`. GrumPHP runs psalm/phpcs/cs-fixer/phpunit on every commit via Docker; all four must pass.

---

## File map

### NEW: `packages/nexus-http-ws/`

```
composer.json
phpunit.xml
README.md
CHANGELOG.md
src/
  Application.php                                  (interface)
  CompiledApplication.php                          (interface)
  HttpApplication.php                              (concrete, HTTP-only)
  CompiledHttpApplication.php                      (concrete)
  WsApplication.php                                (decorator)
  CompiledWsApplication.php                        (concrete)
  WebSocket/
    Attribute/FromContext.php
    Exception/UnsupportedRouteException.php
    Exception/DuplicateRouteException.php
    Message/ChannelConnectionOpened.php
    Message/ChannelMessageReceived.php
    Message/ChannelConnectionClosed.php
    ChannelActorNameResolver.php
    ChannelActorRegistry.php
    ConnectionTable.php                            (interface)
    InMemoryConnectionTable.php
    WebSocketChannelActor.php                      (abstract)
    WebSocketContext.php                           (interface)
    WebSocketDispatcher.php
    WebSocketFrame.php
    WebSocketHandler.php                           (abstract POPO)
    WebSocketRoute.php
    WebSocketRouter.php
tests/Unit/
  HttpApplicationTest.php
  WsApplicationTest.php
  CompiledHttpApplicationTest.php
  CompiledWsApplicationTest.php
  WebSocket/
    WebSocketFrameTest.php
    WebSocketRouterTest.php
    WebSocketDispatcherTest.php
    WebSocketChannelActorTest.php
    InMemoryConnectionTableTest.php
    ChannelActorNameResolverTest.php
    ChannelActorRegistryTest.php
    Support/
      InMemoryWebSocketContext.php
      RecordingHandler.php
      RecordingChannelActor.php
```

### SHRINK: `packages/nexus-http-server-swoole/`

- DELETE `src/App/SwooleHttpApp.php`, `src/App/SwooleCompiledHttpApp.php`
- DELETE entire `src/WebSocket/*` (everything moves to `nexus-http-ws`)
- RENAME `src/Server/SwooleWorkerHttpServer.php` → `src/Server/SwooleWorkerServer.php`
- ADD `src/Server/SwooleConnectionContext.php` (the renamed-and-moved former `LocalWebSocketContext`)
- KEEP unchanged: `src/Server/SwooleWorkerConfig.php`, `src/Server/WorkerServerRuntime.php`, `src/Server/SwooleHttpServerAdapter.php`, `src/Bridge/*`, `src/Signal/*`

### SHRINK: `packages/nexus-http-server-swoole-threads/`

- RENAME `src/Server/SwooleThreadHttpServer.php` → `src/Server/SwooleThreadServer.php`
- RENAME `src/WebSocket/ThreadAwareWebSocketContext.php` → `src/Server/ThreadAwareConnectionContext.php`
- KEEP unchanged: `src/Server/SwooleThreadConfig.php`, `src/Server/ThreadServerRuntime.php`, `src/Actor/WorkerNodePoolSingletonSpawner.php`, `src/WebSocket/Message/WebSocketFramePush.php`

### MIGRATE: tests

- `tests/Integration/HttpSwoole/*` — import renames, DSL rename
- `tests/Performance/HttpSwoole/*`, `tests/Performance/HttpSwooleThreads/*` — import renames

### MODIFY: root config

- `composer.json` — add `nexus-http-ws` to autoload + replace map; bump all package versions in lockstep
- `phpunit.xml` — add `packages/nexus-http-ws/tests/Unit` to `unit` testsuite
- `deptrac.yaml` — new `HttpWs` layer
- `packages/nexus-http-server-swoole/composer.json`, `packages/nexus-http-server-swoole-threads/composer.json` — add `nexus-actors/http-ws` dep

---

## Task list

1. Scaffold `nexus-http-ws` package
2. WebSocket value objects (Frame, Route, Context interface, exceptions, FromContext attribute)
3. `WebSocketRouter` with `assertNoChannelRoutes`
4. `Application` and `CompiledApplication` interfaces
5. `HttpApplication` and `CompiledHttpApplication`
6. `WebSocketHandler` abstract base + injection contract
7. `ConnectionTable` interface + `InMemoryConnectionTable`
8. Channel system messages + `ChannelActorNameResolver`
9. `ChannelActorRegistry`
10. `WebSocketChannelActor` abstract base
11. `WebSocketDispatcher`
12. `WsApplication` + `CompiledWsApplication`
13. Sanity check — full `nexus-http-ws` test suite + coverage gate
14. Shrink + rewrite `nexus-http-server-swoole` (worker runner)
15. Shrink + rewrite `nexus-http-server-swoole-threads` (thread runner)
16. Migrate worker-mode integration tests
17. Migrate thread-mode integration tests
18. Migrate performance tests
19. Delete obsolete files in old packages
20. README updates
21. Final verification matrix + commit cleanup

---

## Task 1: Scaffold `nexus-http-ws` package

**Files:**
- Create: `packages/nexus-http-ws/composer.json`
- Create: `packages/nexus-http-ws/phpunit.xml`
- Create: `packages/nexus-http-ws/CHANGELOG.md`
- Create: `packages/nexus-http-ws/.gitignore`
- Modify: `composer.json` (root) — autoload + replace
- Modify: `phpunit.xml` (root) — add testsuite dir
- Modify: `deptrac.yaml` — add `HttpWs` layer

- [ ] **Step 1: Create the package composer.json**

Write `packages/nexus-http-ws/composer.json`:

```json
{
  "name": "nexus-actors/http-ws",
  "description": "Runtime-agnostic WebSocket DSL for nexus-http.",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": "^8.5",
    "psr/container": "^2.0",
    "psr/http-message": "^2.0",
    "psr/http-server-handler": "^1.0",
    "psr/log": "^3.0",
    "nikic/fast-route": "^1.3",
    "nexus-actors/core": "self.version",
    "nexus-actors/http": "self.version"
  },
  "require-dev": { "phpunit/phpunit": "^13.0" },
  "autoload": { "psr-4": { "Monadial\\Nexus\\Http\\Ws\\": "src/" } },
  "autoload-dev": { "psr-4": { "Monadial\\Nexus\\Http\\Ws\\Tests\\": "tests/" } },
  "minimum-stability": "stable",
  "prefer-stable": true,
  "config": { "sort-packages": true }
}
```

- [ ] **Step 2: Create the package phpunit.xml**

Write `packages/nexus-http-ws/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/13.0/phpunit.xsd"
         bootstrap="../../vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit"><directory>tests/Unit</directory></testsuite>
    </testsuites>
    <source><include><directory>src</directory></include></source>
</phpunit>
```

- [ ] **Step 3: Create CHANGELOG.md**

Write `packages/nexus-http-ws/CHANGELOG.md`:

```markdown
# Changelog

## Unreleased

- Initial release.
```

- [ ] **Step 4: Create .gitignore**

Write `packages/nexus-http-ws/.gitignore`:

```
/.phpunit.cache/
/vendor/
```

- [ ] **Step 5: Wire root composer.json**

In `composer.json` (root) `replace` map add `"nexus-actors/http-ws": "self.version",`. In root `autoload.psr-4` add `"Monadial\\Nexus\\Http\\Ws\\": "packages/nexus-http-ws/src/",`. In root `autoload-dev.psr-4` add `"Monadial\\Nexus\\Http\\Ws\\Tests\\": "packages/nexus-http-ws/tests/",`.

- [ ] **Step 6: Wire root phpunit.xml**

In the root `phpunit.xml` `<testsuite name="unit">` block add `<directory>packages/nexus-http-ws/tests/Unit</directory>`.

- [ ] **Step 7: Wire deptrac.yaml**

In `deptrac.yaml` `layers:` add (alphabetical, near `Http`):

```yaml
  - name: HttpWs
    collectors:
      - type: classLike
        regex: ^Monadial\\Nexus\\Http\\Ws\\.*$
```

In `ruleset:` add `HttpWs: [Core, Http]` and append `HttpWs` to the `HttpServerSwoole` and `HttpServerSwooleThreads` allowed-deps lists.

- [ ] **Step 8: Refresh autoloader and verify**

```bash
docker exec -i nexus-php-1 composer dump-autoload
docker exec -i nexus-php-1 vendor/bin/phpunit --testsuite=unit --filter='NoSuchTest' 2>&1 | tail -3
```

Expected: `Generating optimized autoload files`; phpunit `No tests executed!` without errors.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-http-ws/ composer.json phpunit.xml deptrac.yaml
git -c commit.gpgsign=false commit -m "feat(http-ws): scaffold nexus-http-ws package"
```

---

## Task 2: WebSocket value objects + exceptions + FromContext

**Files:**
- Create: `packages/nexus-http-ws/src/WebSocket/WebSocketFrame.php`
- Create: `packages/nexus-http-ws/src/WebSocket/WebSocketContext.php`
- Create: `packages/nexus-http-ws/src/WebSocket/WebSocketRoute.php`
- Create: `packages/nexus-http-ws/src/WebSocket/Exception/UnsupportedRouteException.php`
- Create: `packages/nexus-http-ws/src/WebSocket/Exception/DuplicateRouteException.php`
- Create: `packages/nexus-http-ws/src/WebSocket/Attribute/FromContext.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketFrameTest.php`

- [ ] **Step 1: Failing test for WebSocketFrame**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketFrameTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketFrame::class)]
final class WebSocketFrameTest extends TestCase
{
    #[Test]
    public function text_frame_carries_text_payload(): void
    {
        $f = new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'hi');
        self::assertSame(WebSocketFrame::KIND_TEXT, $f->kind);
        self::assertSame('hi', $f->text);
    }

    #[Test]
    public function binary_frame_carries_binary_payload(): void
    {
        $f = new WebSocketFrame(WebSocketFrame::KIND_BINARY, "\x00\x01");
        self::assertSame(WebSocketFrame::KIND_BINARY, $f->kind);
        self::assertSame("\x00\x01", $f->text);
    }
}
```

- [ ] **Step 2: Verify failure**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketFrameTest.php 2>&1 | tail -5
```

Expected: `Class "Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame" does not exist`.

- [ ] **Step 3: Implement WebSocketFrame**

Write `packages/nexus-http-ws/src/WebSocket/WebSocketFrame.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

/** @psalm-api */
final readonly class WebSocketFrame
{
    public const int KIND_TEXT = 1;
    public const int KIND_BINARY = 2;

    public function __construct(public int $kind, public string $text)
    {
    }
}
```

- [ ] **Step 4: Verify pass**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketFrameTest.php 2>&1 | tail -3
```

Expected: `OK (2 tests, 4 assertions)`.

- [ ] **Step 5: Implement WebSocketContext interface**

Write `packages/nexus-http-ws/src/WebSocket/WebSocketContext.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
interface WebSocketContext
{
    public function id(): int;
    public function request(): ServerRequestInterface;
    public function send(string $text): void;
    public function sendBinary(string $data): void;
    public function sendPing(): void;
    public function close(int $code = 1000, string $reason = ''): void;
    public function isAlive(): bool;
}
```

- [ ] **Step 6: Implement WebSocketRoute**

Write `packages/nexus-http-ws/src/WebSocket/WebSocketRoute.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

/** @psalm-api */
final readonly class WebSocketRoute
{
    public const string MODE_HANDLER = 'handler';
    public const string MODE_CHANNEL = 'channel';

    /** @param class-string<WebSocketHandler>|class-string<WebSocketChannelActor> $targetClass */
    public function __construct(
        public string $mode,
        public string $path,
        public string $targetClass,
        public ?string $keyFrom,
    ) {
    }

    /** @param class-string<WebSocketHandler> $handlerClass */
    public static function handler(string $path, string $handlerClass): self
    {
        return new self(self::MODE_HANDLER, $path, $handlerClass, null);
    }

    /** @param class-string<WebSocketChannelActor> $actorClass */
    public static function channel(string $path, string $actorClass, string $keyFrom): self
    {
        return new self(self::MODE_CHANNEL, $path, $actorClass, $keyFrom);
    }
}
```

- [ ] **Step 7: Exception types**

Write `packages/nexus-http-ws/src/WebSocket/Exception/UnsupportedRouteException.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket\Exception;

use RuntimeException;

/** @psalm-api */
final class UnsupportedRouteException extends RuntimeException
{
}
```

Write `packages/nexus-http-ws/src/WebSocket/Exception/DuplicateRouteException.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket\Exception;

use RuntimeException;

/** @psalm-api */
final class DuplicateRouteException extends RuntimeException
{
}
```

- [ ] **Step 8: FromContext attribute**

Write `packages/nexus-http-ws/src/WebSocket/Attribute/FromContext.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket\Attribute;

use Attribute;

/** @psalm-api */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromContext
{
}
```

- [ ] **Step 9: Lint + test**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketFrameTest.php
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: `OK (2 tests, 4 assertions)`; psalm + phpcs clean.

- [ ] **Step 10: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): WebSocket value objects + exceptions + FromContext"
```

---

## Task 3: WebSocketRouter

**Files:**
- Create: `packages/nexus-http-ws/src/WebSocket/WebSocketRouter.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketRouterTest.php`

- [ ] **Step 1: Failing tests**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketRouterTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\WebSocket\Exception\UnsupportedRouteException;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketRouter::class)]
final class WebSocketRouterTest extends TestCase
{
    #[Test]
    public function matches_static_handler_path(): void
    {
        $r = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', FakeHandlerClass::class)]);
        $m = $r->match('/ws/echo');
        self::assertNotNull($m);
        self::assertSame(WebSocketRoute::MODE_HANDLER, $m['route']->mode);
        self::assertSame([], $m['params']);
    }

    #[Test]
    public function matches_channel_path_and_extracts_params(): void
    {
        $r = WebSocketRouter::build([
            WebSocketRoute::channel('/ws/room/{roomId}', FakeChannelActorClass::class, 'roomId'),
        ]);
        $m = $r->match('/ws/room/lobby');
        self::assertNotNull($m);
        self::assertSame(WebSocketRoute::MODE_CHANNEL, $m['route']->mode);
        self::assertSame(['roomId' => 'lobby'], $m['params']);
    }

    #[Test]
    public function returns_null_on_no_match(): void
    {
        $r = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', FakeHandlerClass::class)]);
        self::assertNull($r->match('/missing'));
    }

    #[Test]
    public function routes_accessor_returns_registered_routes(): void
    {
        $a = WebSocketRoute::handler('/ws/a', FakeHandlerClass::class);
        $b = WebSocketRoute::handler('/ws/b', FakeHandlerClass::class);
        $r = WebSocketRouter::build([$a, $b]);
        self::assertSame([$a, $b], $r->routes());
    }

    #[Test]
    public function assert_no_channel_routes_throws_when_channel_present(): void
    {
        $r = WebSocketRouter::build([
            WebSocketRoute::handler('/ws/echo', FakeHandlerClass::class),
            WebSocketRoute::channel('/ws/room/{id}', FakeChannelActorClass::class, 'id'),
        ]);
        $this->expectException(UnsupportedRouteException::class);
        $this->expectExceptionMessage('/ws/room/{id}');
        $r->assertNoChannelRoutes();
    }

    #[Test]
    public function assert_no_channel_routes_passes_for_handler_only(): void
    {
        $r = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', FakeHandlerClass::class)]);
        $r->assertNoChannelRoutes();
        self::assertTrue(true);
    }

    #[Test]
    public function empty_router_matches_nothing_and_returns_no_routes(): void
    {
        $r = WebSocketRouter::build([]);
        self::assertNull($r->match('/x'));
        self::assertSame([], $r->routes());
    }
}

/** @internal */
final class FakeHandlerClass {}
/** @internal */
final class FakeChannelActorClass {}
```

- [ ] **Step 2: Verify failure**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketRouterTest.php 2>&1 | tail -5
```

Expected: `Class "Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter" does not exist`.

- [ ] **Step 3: Implement WebSocketRouter**

Write `packages/nexus-http-ws/src/WebSocket/WebSocketRouter.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\UnsupportedRouteException;

use function array_values;
use function FastRoute\simpleDispatcher;

/** @psalm-api */
final class WebSocketRouter
{
    /** @param array<int, WebSocketRoute> $routes */
    private function __construct(
        private readonly Dispatcher $delegate,
        private readonly array $routes,
    ) {
    }

    /** @param list<WebSocketRoute> $routes */
    public static function build(array $routes): self
    {
        $byId = [];
        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($routes, &$byId): void {
            foreach ($routes as $id => $route) {
                $byId[$id] = $route;
                $r->addRoute('GET', $route->path, $id);
            }
        });
        /** @var array<int, WebSocketRoute> $byId */
        return new self($dispatcher, $byId);
    }

    /** @return list<WebSocketRoute> */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    /** @return array{route: WebSocketRoute, params: array<string,string>}|null */
    public function match(string $path): ?array
    {
        $info = $this->delegate->dispatch('GET', $path);
        if ($info[0] !== Dispatcher::FOUND) {
            return null;
        }
        /** @var int $id */
        $id = $info[1];
        /** @var array<string,string> $params */
        $params = $info[2];
        return ['params' => $params, 'route' => $this->routes[$id]];
    }

    public function assertNoChannelRoutes(): void
    {
        foreach ($this->routes as $route) {
            if ($route->mode === WebSocketRoute::MODE_CHANNEL) {
                throw new UnsupportedRouteException(
                    "WebSocket channel-actor routes are not supported in this runtime "
                    . "(route '{$route->path}'). Use handler-mode WebSocket here, "
                    . 'or switch to the worker-mode runner for channel actors.',
                );
            }
        }
    }
}
```

- [ ] **Step 4: Verify pass + lint**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketRouterTest.php
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: `OK (7 tests, 12 assertions)`; psalm + phpcs clean.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): WebSocketRouter with assertNoChannelRoutes"
```

---

## Task 4: `Application` and `CompiledApplication` interfaces

**Files:**
- Create: `packages/nexus-http-ws/src/Application.php`
- Create: `packages/nexus-http-ws/src/CompiledApplication.php`

- [ ] **Step 1: Implement `CompiledApplication` interface**

Write `packages/nexus-http-ws/src/CompiledApplication.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws;

use Psr\Http\Server\RequestHandlerInterface;

/** @psalm-api */
interface CompiledApplication extends RequestHandlerInterface
{
    public function hasWebSocketRoutes(): bool;
}
```

- [ ] **Step 2: Implement `Application` interface**

Write `packages/nexus-http-ws/src/Application.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws;

use Closure;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\ActorRegistration;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\App\PoolSingletonSpawner;
use Monadial\Nexus\Http\Dsl\RouteBuilder;
use Monadial\Nexus\Http\Dsl\RouteGroup;
use Monadial\Nexus\Serialization\MessageSerializer;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @psalm-api
 *
 * Defines the HTTP surface (every method nexus-http's HttpApp exposes today)
 * plus compile(). Concrete impls: HttpApplication (HTTP-only) and
 * WsApplication (decorator that adds WebSocket).
 */
interface Application
{
    public function get(string $path, string|Closure $handler): RouteBuilder;
    public function post(string $path, string|Closure $handler): RouteBuilder;
    public function put(string $path, string|Closure $handler): RouteBuilder;
    public function patch(string $path, string|Closure $handler): RouteBuilder;
    public function delete(string $path, string|Closure $handler): RouteBuilder;
    public function group(string $prefix, Closure $register): RouteGroup;
    public function middleware(string|MiddlewareInterface $middleware): self;
    public function actor(string $name, Props $props): ActorRegistration;
    public function perRequestActor(string $name, Props $props): ActorRegistration;
    public function discover(string $directory): self;
    public function errorMode(ErrorMode $mode): self;
    public function onException(string $exceptionClass, Closure $mapper): self;
    public function withPoolSingletonSpawner(PoolSingletonSpawner $spawner): self;
    public function withMessageSerializer(MessageSerializer $serializer): self;
    public function withRouteCache(CacheInterface $cache, ?string $key = null): self;
    public function withoutDefaultExceptionHandler(): self;
    public function clearRouteCache(): void;

    public function compile(): CompiledApplication;
}
```

NOTE: if any of the imported namespaces above don't exist exactly as written, run `docker exec -i nexus-php-1 grep -rn "namespace " packages/nexus-http/src/<dir>` to locate the right one and adjust the `use` accordingly. Do NOT invent classes.

- [ ] **Step 3: Verify autoload + psalm**

```bash
docker exec -i nexus-php-1 composer dump-autoload
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: psalm + phpcs clean. If psalm reports an undefined class in `use`, fix the namespace path.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): Application + CompiledApplication interfaces"
```

---

## Task 5: `HttpApplication` and `CompiledHttpApplication`

**Files:**
- Create: `packages/nexus-http-ws/src/CompiledHttpApplication.php`
- Create: `packages/nexus-http-ws/src/HttpApplication.php`
- Create: `packages/nexus-http-ws/tests/Unit/CompiledHttpApplicationTest.php`
- Create: `packages/nexus-http-ws/tests/Unit/HttpApplicationTest.php`

- [ ] **Step 1: Failing test for CompiledHttpApplication**

Write `packages/nexus-http-ws/tests/Unit/CompiledHttpApplicationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Runtime\Test\TestRuntime;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Ws\CompiledHttpApplication;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledHttpApplication::class)]
final class CompiledHttpApplicationTest extends TestCase
{
    #[Test]
    public function delegates_handle_to_wrapped_compiled_http_app(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $http   = HttpApp::create($system);
        $http->get('/ping', static fn() => Response::ok('pong'));

        $c = new CompiledHttpApplication($http->compile());

        $resp = $c->handle(new ServerRequest('GET', '/ping'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('pong', (string) $resp->getBody());
    }

    #[Test]
    public function has_web_socket_routes_is_always_false(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $http   = HttpApp::create($system);
        $c      = new CompiledHttpApplication($http->compile());

        self::assertFalse($c->hasWebSocketRoutes());
    }
}
```

NOTE: if `TestRuntime` lives at a different path, run `docker exec -i nexus-php-1 find packages/nexus-core -name 'TestRuntime.php'` and adjust the `use`. Same for `Response::ok` — confirm via `grep -rn 'public static function ok' packages/nexus-http/src/Response/`.

- [ ] **Step 2: Verify failure**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/CompiledHttpApplicationTest.php 2>&1 | tail -5
```

Expected: `Class "Monadial\Nexus\Http\Ws\CompiledHttpApplication" does not exist`.

- [ ] **Step 3: Implement CompiledHttpApplication**

Write `packages/nexus-http-ws/src/CompiledHttpApplication.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws;

use Monadial\Nexus\Http\App\CompiledHttpApp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final class CompiledHttpApplication implements CompiledApplication
{
    public function __construct(private readonly CompiledHttpApp $http)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->http->handle($request);
    }

    public function hasWebSocketRoutes(): bool
    {
        return false;
    }

    public function inner(): CompiledHttpApp
    {
        return $this->http;
    }
}
```

- [ ] **Step 4: Verify CompiledHttpApplication test passes**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/CompiledHttpApplicationTest.php 2>&1 | tail -3
```

Expected: `OK (2 tests, 3 assertions)`.

- [ ] **Step 5: Failing test for HttpApplication**

Write `packages/nexus-http-ws/tests/Unit/HttpApplicationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Runtime\Test\TestRuntime;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Ws\CompiledHttpApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpApplication::class)]
final class HttpApplicationTest extends TestCase
{
    #[Test]
    public function get_route_is_registered_and_served(): void
    {
        $app = HttpApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->get('/hello', static fn() => Response::ok('world'));

        $resp = $app->compile()->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('world', (string) $resp->getBody());
    }

    #[Test]
    public function compile_returns_compiled_http_application(): void
    {
        $app = HttpApplication::create(ActorSystem::create('t', new TestRuntime()));

        self::assertInstanceOf(CompiledHttpApplication::class, $app->compile());
    }

    #[Test]
    public function middleware_is_delegated(): void
    {
        $app = HttpApplication::create(ActorSystem::create('t', new TestRuntime()));
        $returned = $app->middleware(static fn($r, $h) => $h->handle($r));

        self::assertSame($app, $returned);
    }
}
```

- [ ] **Step 6: Verify failure**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/HttpApplicationTest.php 2>&1 | tail -5
```

Expected: class not found.

- [ ] **Step 7: Implement HttpApplication**

Write `packages/nexus-http-ws/src/HttpApplication.php`. Implements every `Application` method by delegating to an internal `HttpApp`. Fluent setters return `$this` so chains stay on `HttpApplication`. Compile returns `CompiledHttpApplication`.

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\ActorRegistration;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\App\PoolSingletonSpawner;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Dsl\RouteBuilder;
use Monadial\Nexus\Http\Dsl\RouteGroup;
use Monadial\Nexus\Serialization\MessageSerializer;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @psalm-api
 *
 * Concrete HTTP-only Application implementation. HAS-A nexus-http HttpApp;
 * delegates every method. compile() returns CompiledHttpApplication.
 */
final class HttpApplication implements Application
{
    private function __construct(private readonly HttpApp $http)
    {
    }

    public static function create(ActorSystem $system): self
    {
        return new self(HttpApp::create($system));
    }

    public function inner(): HttpApp
    {
        return $this->http;
    }

    public function get(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->get($path, $handler);
    }

    public function post(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->post($path, $handler);
    }

    public function put(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->put($path, $handler);
    }

    public function patch(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->patch($path, $handler);
    }

    public function delete(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->delete($path, $handler);
    }

    public function group(string $prefix, Closure $register): RouteGroup
    {
        return $this->http->group($prefix, $register);
    }

    public function middleware(string|MiddlewareInterface $middleware): self
    {
        $this->http->middleware($middleware);
        return $this;
    }

    public function actor(string $name, Props $props): ActorRegistration
    {
        return $this->http->actor($name, $props);
    }

    public function perRequestActor(string $name, Props $props): ActorRegistration
    {
        return $this->http->perRequestActor($name, $props);
    }

    public function discover(string $directory): self
    {
        $this->http->discover($directory);
        return $this;
    }

    public function errorMode(ErrorMode $mode): self
    {
        $this->http->errorMode($mode);
        return $this;
    }

    public function onException(string $exceptionClass, Closure $mapper): self
    {
        $this->http->onException($exceptionClass, $mapper);
        return $this;
    }

    public function withPoolSingletonSpawner(PoolSingletonSpawner $spawner): self
    {
        $this->http->withPoolSingletonSpawner($spawner);
        return $this;
    }

    public function withMessageSerializer(MessageSerializer $serializer): self
    {
        $this->http->withMessageSerializer($serializer);
        return $this;
    }

    public function withRouteCache(CacheInterface $cache, ?string $key = null): self
    {
        $this->http->withRouteCache($cache, $key);
        return $this;
    }

    public function withoutDefaultExceptionHandler(): self
    {
        $this->http->withoutDefaultExceptionHandler();
        return $this;
    }

    public function clearRouteCache(): void
    {
        $this->http->clearRouteCache();
    }

    public function compile(): CompiledHttpApplication
    {
        return new CompiledHttpApplication($this->http->compile());
    }
}
```

NOTE: signatures must exactly match `HttpApp` — if any nexus-http method's return type differs from what's written above, adjust ours to match. Run `grep -nE "^\s*public function" packages/nexus-http/src/Dsl/HttpApp.php` to confirm.

- [ ] **Step 8: Verify both tests pass**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/HttpApplicationTest.php packages/nexus-http-ws/tests/Unit/CompiledHttpApplicationTest.php 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: `OK (5 tests, ...)`; psalm + phpcs clean.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): HttpApplication + CompiledHttpApplication"
```

---

## Task 6: `WebSocketHandler` abstract base

**Files:**
- Create: `packages/nexus-http-ws/src/WebSocket/WebSocketHandler.php`

- [ ] **Step 1: Implement WebSocketHandler**

Write `packages/nexus-http-ws/src/WebSocket/WebSocketHandler.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

/**
 * @psalm-api
 *
 * Per-connection POPO base. One instance per WebSocket connection,
 * resolved via PSR-11. Constructor parameters can use #[FromContext]
 * to inject the current WebSocketContext and #[FromActor('name')] to
 * inject any registered ActorRef; other parameters resolve through
 * the container normally.
 */
abstract class WebSocketHandler
{
    public function onOpen(): void
    {
    }

    abstract public function onMessage(WebSocketFrame $frame): void;

    public function onClose(int $code): void
    {
    }
}
```

NOTE: no failing test here — this is an abstract base with empty default `onOpen`/`onClose` and one `abstract` declaration. Behavior is verified end-to-end in the `WebSocketDispatcherTest` (Task 11).

- [ ] **Step 2: Lint**

```bash
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: clean.

- [ ] **Step 3: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): WebSocketHandler abstract base"
```

---

## Task 7: `ConnectionTable` interface + `InMemoryConnectionTable`

**Files:**
- Create: `packages/nexus-http-ws/src/WebSocket/ConnectionTable.php`
- Create: `packages/nexus-http-ws/src/WebSocket/InMemoryConnectionTable.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/InMemoryConnectionTableTest.php`

- [ ] **Step 1: Implement ConnectionTable interface**

Write `packages/nexus-http-ws/src/WebSocket/ConnectionTable.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @psalm-api
 *
 * @phpstan-type ConnectionEntry array{
 *   handler: WebSocketHandler|null,
 *   channelActor: ActorRef|null,
 *   channelName: string|null,
 *   ctx: WebSocketContext
 * }
 */
interface ConnectionTable
{
    public function attachHandler(int $fd, WebSocketHandler $handler, WebSocketContext $ctx): void;

    public function attachChannel(int $fd, ActorRef $actor, string $channelName, WebSocketContext $ctx): void;

    /**
     * @return array{handler: WebSocketHandler|null, channelActor: ActorRef|null, channelName: string|null, ctx: WebSocketContext}|null
     */
    public function get(int $fd): ?array;

    public function remove(int $fd): void;

    public function has(int $fd): bool;

    /** @return list<int> */
    public function fds(): array;
}
```

- [ ] **Step 2: Failing test for InMemoryConnectionTable**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/InMemoryConnectionTableTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\FakeActorRef;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\InMemoryWebSocketContext;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\NullHandler;
use Monadial\Nexus\Http\Ws\WebSocket\InMemoryConnectionTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryConnectionTable::class)]
final class InMemoryConnectionTableTest extends TestCase
{
    #[Test]
    public function attach_handler_stores_handler_entry(): void
    {
        $t = new InMemoryConnectionTable();
        $h = new NullHandler();
        $ctx = new InMemoryWebSocketContext(1);
        $t->attachHandler(1, $h, $ctx);

        $e = $t->get(1);
        self::assertNotNull($e);
        self::assertSame($h, $e['handler']);
        self::assertNull($e['channelActor']);
        self::assertNull($e['channelName']);
        self::assertSame($ctx, $e['ctx']);
    }

    #[Test]
    public function attach_channel_stores_channel_entry(): void
    {
        $t = new InMemoryConnectionTable();
        $ref = new FakeActorRef();
        $ctx = new InMemoryWebSocketContext(2);
        $t->attachChannel(2, $ref, 'room/lobby', $ctx);

        $e = $t->get(2);
        self::assertNotNull($e);
        self::assertNull($e['handler']);
        self::assertSame($ref, $e['channelActor']);
        self::assertSame('room/lobby', $e['channelName']);
        self::assertSame($ctx, $e['ctx']);
    }

    #[Test]
    public function get_returns_null_for_unknown_fd(): void
    {
        self::assertNull((new InMemoryConnectionTable())->get(42));
    }

    #[Test]
    public function has_reflects_attach_and_remove(): void
    {
        $t = new InMemoryConnectionTable();
        self::assertFalse($t->has(7));
        $t->attachHandler(7, new NullHandler(), new InMemoryWebSocketContext(7));
        self::assertTrue($t->has(7));
        $t->remove(7);
        self::assertFalse($t->has(7));
    }

    #[Test]
    public function fds_lists_all_attached(): void
    {
        $t = new InMemoryConnectionTable();
        $t->attachHandler(1, new NullHandler(), new InMemoryWebSocketContext(1));
        $t->attachHandler(2, new NullHandler(), new InMemoryWebSocketContext(2));

        $fds = $t->fds();
        sort($fds);
        self::assertSame([1, 2], $fds);
    }
}
```

NOTE: this test references three support fixtures (`InMemoryWebSocketContext`, `NullHandler`, `FakeActorRef`). Create them now in `packages/nexus-http-ws/tests/Unit/WebSocket/Support/`. These same fixtures will be reused in later tasks.

- [ ] **Step 3: Create test support fixtures**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/Support/InMemoryWebSocketContext.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Test double for WebSocketContext that records sends and tracks aliveness.
 * Not a runner — drives the dispatcher in unit tests without Swoole.
 */
final class InMemoryWebSocketContext implements WebSocketContext
{
    /** @var list<string> */
    public array $sentText = [];

    /** @var list<string> */
    public array $sentBinary = [];

    public int $pings = 0;

    public bool $closed = false;

    public int $closeCode = 0;

    public string $closeReason = '';

    private bool $alive = true;

    public function __construct(
        private readonly int $id,
        private readonly string $path = '/',
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', $this->path);
    }

    public function send(string $text): void
    {
        $this->sentText[] = $text;
    }

    public function sendBinary(string $data): void
    {
        $this->sentBinary[] = $data;
    }

    public function sendPing(): void
    {
        $this->pings++;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->closed = true;
        $this->closeCode = $code;
        $this->closeReason = $reason;
        $this->alive = false;
    }

    public function isAlive(): bool
    {
        return $this->alive;
    }
}
```

Write `packages/nexus-http-ws/tests/Unit/WebSocket/Support/NullHandler.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;

final class NullHandler extends WebSocketHandler
{
    public function onMessage(WebSocketFrame $frame): void
    {
    }
}
```

Write `packages/nexus-http-ws/tests/Unit/WebSocket/Support/FakeActorRef.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Duration;

/**
 * Test double for ActorRef that records tell() invocations.
 *
 * @template-extends ActorRef<object>
 */
final class FakeActorRef implements ActorRef
{
    /** @var list<object> */
    public array $told = [];

    public function tell(object $message): void
    {
        $this->told[] = $message;
    }

    public function ask(callable $factory, Duration $timeout): mixed
    {
        throw new \RuntimeException('not implemented in fake');
    }

    public function path(): ActorPath
    {
        return ActorPath::parse('/user/fake');
    }

    public function isAlive(): bool
    {
        return true;
    }
}
```

NOTE: `ActorRef` is a generic interface; confirm template/return types via `grep -A2 "interface ActorRef" packages/nexus-core/src/Actor/ActorRef.php`. Adjust method bodies to match the contract — the fake only needs `tell()` to actually record.

- [ ] **Step 4: Verify failure**

```bash
docker exec -i nexus-php-1 composer dump-autoload
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/InMemoryConnectionTableTest.php 2>&1 | tail -5
```

Expected: class not found for `InMemoryConnectionTable`.

- [ ] **Step 5: Implement InMemoryConnectionTable**

Write `packages/nexus-http-ws/src/WebSocket/InMemoryConnectionTable.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;

use function array_keys;

/** @psalm-api */
final class InMemoryConnectionTable implements ConnectionTable
{
    /**
     * @var array<int, array{
     *   handler: WebSocketHandler|null,
     *   channelActor: ActorRef|null,
     *   channelName: string|null,
     *   ctx: WebSocketContext
     * }>
     */
    private array $entries = [];

    public function attachHandler(int $fd, WebSocketHandler $handler, WebSocketContext $ctx): void
    {
        $this->entries[$fd] = [
            'channelActor' => null,
            'channelName' => null,
            'ctx' => $ctx,
            'handler' => $handler,
        ];
    }

    public function attachChannel(int $fd, ActorRef $actor, string $channelName, WebSocketContext $ctx): void
    {
        $this->entries[$fd] = [
            'channelActor' => $actor,
            'channelName' => $channelName,
            'ctx' => $ctx,
            'handler' => null,
        ];
    }

    public function get(int $fd): ?array
    {
        return $this->entries[$fd] ?? null;
    }

    public function remove(int $fd): void
    {
        unset($this->entries[$fd]);
    }

    public function has(int $fd): bool
    {
        return isset($this->entries[$fd]);
    }

    /** @return list<int> */
    public function fds(): array
    {
        return array_keys($this->entries);
    }
}
```

- [ ] **Step 6: Verify test passes**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/InMemoryConnectionTableTest.php 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: `OK (5 tests, 12 assertions)`; psalm + phpcs clean.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): ConnectionTable interface + InMemoryConnectionTable"
```

---

## Task 8: Channel system messages + `ChannelActorNameResolver`

**Files:**
- Create: `packages/nexus-http-ws/src/WebSocket/Message/ChannelConnectionOpened.php`
- Create: `packages/nexus-http-ws/src/WebSocket/Message/ChannelMessageReceived.php`
- Create: `packages/nexus-http-ws/src/WebSocket/Message/ChannelConnectionClosed.php`
- Create: `packages/nexus-http-ws/src/WebSocket/ChannelActorNameResolver.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/ChannelActorNameResolverTest.php`

- [ ] **Step 1: Implement ChannelConnectionOpened**

Write `packages/nexus-http-ws/src/WebSocket/Message/ChannelConnectionOpened.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket\Message;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 *
 * Sent by the dispatcher to a channel actor when a new connection joins.
 * Translated into the actor's onOpened() hook by WebSocketChannelActor.
 */
final readonly class ChannelConnectionOpened
{
    public function __construct(
        public int $fd,
        public WebSocketContext $ctx,
        public ServerRequestInterface $upgradeRequest,
    ) {
    }
}
```

- [ ] **Step 2: Implement ChannelMessageReceived**

Write `packages/nexus-http-ws/src/WebSocket/Message/ChannelMessageReceived.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket\Message;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;

/** @internal */
final readonly class ChannelMessageReceived
{
    public function __construct(public int $fd, public WebSocketFrame $frame)
    {
    }
}
```

- [ ] **Step 3: Implement ChannelConnectionClosed**

Write `packages/nexus-http-ws/src/WebSocket/Message/ChannelConnectionClosed.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket\Message;

/** @internal */
final readonly class ChannelConnectionClosed
{
    public function __construct(public int $fd, public int $code)
    {
    }
}
```

- [ ] **Step 4: Failing test for ChannelActorNameResolver**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/ChannelActorNameResolverTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelActorNameResolver::class)]
final class ChannelActorNameResolverTest extends TestCase
{
    #[Test]
    public function resolves_to_deterministic_url_safe_name(): void
    {
        $a = ChannelActorNameResolver::resolve('lobby');
        $b = ChannelActorNameResolver::resolve('lobby');

        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^ws-channel-[a-z0-9]+$/', $a);
    }

    #[Test]
    public function different_keys_produce_different_names(): void
    {
        self::assertNotSame(
            ChannelActorNameResolver::resolve('lobby'),
            ChannelActorNameResolver::resolve('room42'),
        );
    }

    #[Test]
    public function handles_keys_with_unsafe_characters(): void
    {
        $name = ChannelActorNameResolver::resolve('café/42 + 9');

        self::assertMatchesRegularExpression('/^ws-channel-[a-z0-9]+$/', $name);
    }
}
```

- [ ] **Step 5: Verify failure**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/ChannelActorNameResolverTest.php 2>&1 | tail -5
```

Expected: class not found.

- [ ] **Step 6: Implement ChannelActorNameResolver**

Write `packages/nexus-http-ws/src/WebSocket/ChannelActorNameResolver.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

use function hash;

/**
 * @psalm-api
 *
 * Maps a path-param value (key) to a deterministic URL-safe actor name.
 * Uses xxh3 to keep the actor name short and collision-resistant while
 * accepting arbitrary key bytes (including multi-byte chars and reserved
 * URL chars).
 */
final class ChannelActorNameResolver
{
    public static function resolve(string $key): string
    {
        return 'ws-channel-' . hash('xxh3', $key);
    }
}
```

- [ ] **Step 7: Verify pass + lint**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/ChannelActorNameResolverTest.php 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: `OK (3 tests, 5 assertions)`; psalm + phpcs clean.

- [ ] **Step 8: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): channel system messages + ChannelActorNameResolver"
```

---

## Task 9: `ChannelActorRegistry`

**Files:**
- Create: `packages/nexus-http-ws/src/WebSocket/ChannelActorRegistry.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/ChannelActorRegistryTest.php`

- [ ] **Step 1: Failing test**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/ChannelActorRegistryTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Runtime\Test\TestRuntime;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelActorRegistry::class)]
final class ChannelActorRegistryTest extends TestCase
{
    #[Test]
    public function resolve_or_spawn_returns_same_ref_for_same_name(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $reg = new ChannelActorRegistry($system);
        $props = Props::fromBehavior(Behavior::receive(static fn() => Behavior::same()));

        $a = $reg->resolveOrSpawn('ws-channel-x', $props);
        $b = $reg->resolveOrSpawn('ws-channel-x', $props);

        self::assertSame($a, $b);
    }

    #[Test]
    public function resolve_or_spawn_returns_different_refs_for_different_names(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $reg = new ChannelActorRegistry($system);
        $props = Props::fromBehavior(Behavior::receive(static fn() => Behavior::same()));

        $a = $reg->resolveOrSpawn('ws-channel-a', $props);
        $b = $reg->resolveOrSpawn('ws-channel-b', $props);

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function remove_drops_known_name(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $reg = new ChannelActorRegistry($system);
        $props = Props::fromBehavior(Behavior::receive(static fn() => Behavior::same()));

        $reg->resolveOrSpawn('ws-channel-x', $props);
        $reg->remove('ws-channel-x');

        // After remove the next resolveOrSpawn must spawn a fresh actor — not the same ref.
        $fresh = $reg->resolveOrSpawn('ws-channel-x', $props);
        self::assertNotNull($fresh);
    }
}
```

NOTE: confirm `Props::fromBehavior` and `Behavior::receive` namespaces with `grep -rn "public static function" packages/nexus-core/src/Actor/Props.php packages/nexus-core/src/Actor/Behavior.php | head`.

- [ ] **Step 2: Verify failure**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/ChannelActorRegistryTest.php 2>&1 | tail -5
```

Expected: class not found.

- [ ] **Step 3: Implement ChannelActorRegistry**

Write `packages/nexus-http-ws/src/WebSocket/ChannelActorRegistry.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 *
 * Spawns and caches one channel actor per stable name. Used by
 * WebSocketDispatcher; not part of the user-facing API.
 */
final class ChannelActorRegistry
{
    /** @var array<string, ActorRef> */
    private array $refs = [];

    public function __construct(private readonly ActorSystem $system)
    {
    }

    public function resolveOrSpawn(string $name, Props $props): ActorRef
    {
        if (isset($this->refs[$name])) {
            return $this->refs[$name];
        }

        $ref = $this->system->spawn($props, $name);
        $this->refs[$name] = $ref;
        return $ref;
    }

    public function remove(string $name): void
    {
        unset($this->refs[$name]);
    }
}
```

- [ ] **Step 4: Verify pass + lint**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/ChannelActorRegistryTest.php 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: `OK (3 tests, ...)`; psalm + phpcs clean.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): ChannelActorRegistry"
```

---

## Task 10: `WebSocketChannelActor` abstract base

**Files:**
- Create: `packages/nexus-http-ws/src/WebSocket/WebSocketChannelActor.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/Support/RecordingChannelActor.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketChannelActorTest.php`

- [ ] **Step 1: Implement WebSocketChannelActor**

Write `packages/nexus-http-ws/src/WebSocket/WebSocketChannelActor.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelMessageReceived;

use function array_values;

/**
 * @psalm-api
 *
 * Per-key channel actor base. Translates internal Channel*** messages into
 * typed onOpened/onMessage/onClosed hooks. Maintains a connection set the
 * subclass can broadcast over via the protected helpers.
 *
 * @template S
 * @implements StatefulActorHandler<S>
 */
abstract class WebSocketChannelActor implements StatefulActorHandler
{
    /** @var array<int, WebSocketContext> */
    private array $attached = [];

    abstract public function initialState(): mixed;

    /**
     * @param S $state
     * @return BehaviorWithState<self, S>
     */
    public function onOpened(ActorContext $ctx, WebSocketContext $conn, mixed $state): BehaviorWithState
    {
        return BehaviorWithState::same();
    }

    /**
     * @param S $state
     * @return BehaviorWithState<self, S>
     */
    abstract public function onMessage(
        ActorContext $ctx,
        WebSocketContext $conn,
        WebSocketFrame $frame,
        mixed $state,
    ): BehaviorWithState;

    /**
     * @param S $state
     * @return BehaviorWithState<self, S>
     */
    public function onClosed(ActorContext $ctx, WebSocketContext $conn, int $code, mixed $state): BehaviorWithState
    {
        return BehaviorWithState::same();
    }

    /** @return list<WebSocketContext> */
    final protected function connections(): array
    {
        return array_values($this->attached);
    }

    final protected function broadcast(string $text, ?int $exceptFd = null): void
    {
        foreach ($this->attached as $fd => $conn) {
            if ($fd === $exceptFd) {
                continue;
            }
            $conn->send($text);
        }
    }

    /**
     * @param S $state
     * @return BehaviorWithState<self, S>
     */
    final public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        if ($message instanceof ChannelConnectionOpened) {
            $this->attached[$message->fd] = $message->ctx;
            return $this->onOpened($ctx, $message->ctx, $state);
        }

        if ($message instanceof ChannelMessageReceived) {
            $conn = $this->attached[$message->fd] ?? null;
            if ($conn === null) {
                return BehaviorWithState::same();
            }
            return $this->onMessage($ctx, $conn, $message->frame, $state);
        }

        if ($message instanceof ChannelConnectionClosed) {
            $conn = $this->attached[$message->fd] ?? null;
            if ($conn === null) {
                return BehaviorWithState::same();
            }
            unset($this->attached[$message->fd]);
            return $this->onClosed($ctx, $conn, $message->code, $state);
        }

        return BehaviorWithState::same();
    }
}
```

NOTE: confirm `StatefulActorHandler` template signature and `BehaviorWithState::same()` static factory shape with `grep -A5 "interface StatefulActorHandler" packages/nexus-core/src/Actor/StatefulActorHandler.php` and `grep -nE "public static function (same|next)" packages/nexus-core/src/Actor/BehaviorWithState.php`. Adjust template params/return types to match.

- [ ] **Step 2: Test support RecordingChannelActor**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/Support/RecordingChannelActor.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;

/** @extends WebSocketChannelActor<int> */
final class RecordingChannelActor extends WebSocketChannelActor
{
    /** @var list<array{event:string, fd:int}> */
    public array $events = [];

    public function initialState(): mixed
    {
        return 0;
    }

    public function onOpened(ActorContext $ctx, WebSocketContext $conn, mixed $state): BehaviorWithState
    {
        $this->events[] = ['event' => 'opened', 'fd' => $conn->id()];
        return BehaviorWithState::same();
    }

    public function onMessage(
        ActorContext $ctx,
        WebSocketContext $conn,
        WebSocketFrame $frame,
        mixed $state,
    ): BehaviorWithState {
        $this->events[] = ['event' => 'message:' . $frame->text, 'fd' => $conn->id()];
        $this->broadcast('relay:' . $frame->text, exceptFd: $conn->id());
        return BehaviorWithState::same();
    }

    public function onClosed(ActorContext $ctx, WebSocketContext $conn, int $code, mixed $state): BehaviorWithState
    {
        $this->events[] = ['event' => 'closed:' . $code, 'fd' => $conn->id()];
        return BehaviorWithState::same();
    }

    public function publicConnections(): array
    {
        return $this->connections();
    }
}
```

- [ ] **Step 3: Failing tests for WebSocketChannelActor**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketChannelActorTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\InMemoryWebSocketContext;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\RecordingChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelMessageReceived;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketChannelActor::class)]
final class WebSocketChannelActorTest extends TestCase
{
    #[Test]
    public function opened_message_invokes_on_opened_and_tracks_connection(): void
    {
        $a = new RecordingChannelActor();
        $ctx = new InMemoryWebSocketContext(1);

        $a->handle($this->actorContext(), new ChannelConnectionOpened(1, $ctx, new ServerRequest('GET', '/ws')), 0);

        self::assertSame([['event' => 'opened', 'fd' => 1]], $a->events);
        self::assertSame([$ctx], $a->publicConnections());
    }

    #[Test]
    public function message_routed_to_on_message_and_broadcast_excludes_sender(): void
    {
        $a = new RecordingChannelActor();
        $c1 = new InMemoryWebSocketContext(1);
        $c2 = new InMemoryWebSocketContext(2);
        $a->handle($this->actorContext(), new ChannelConnectionOpened(1, $c1, new ServerRequest('GET', '/ws')), 0);
        $a->handle($this->actorContext(), new ChannelConnectionOpened(2, $c2, new ServerRequest('GET', '/ws')), 0);

        $a->handle($this->actorContext(), new ChannelMessageReceived(1, new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'hi')), 0);

        self::assertSame([], $c1->sentText);
        self::assertSame(['relay:hi'], $c2->sentText);
    }

    #[Test]
    public function message_on_unknown_fd_is_ignored(): void
    {
        $a = new RecordingChannelActor();

        $a->handle($this->actorContext(), new ChannelMessageReceived(99, new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'x')), 0);

        self::assertSame([], $a->events);
    }

    #[Test]
    public function closed_removes_connection_and_invokes_hook(): void
    {
        $a = new RecordingChannelActor();
        $ctx = new InMemoryWebSocketContext(7);
        $a->handle($this->actorContext(), new ChannelConnectionOpened(7, $ctx, new ServerRequest('GET', '/ws')), 0);

        $a->handle($this->actorContext(), new ChannelConnectionClosed(7, 1001), 0);

        self::assertSame([], $a->publicConnections());
        self::assertSame(
            [['event' => 'opened', 'fd' => 7], ['event' => 'closed:1001', 'fd' => 7]],
            $a->events,
        );
    }

    #[Test]
    public function unknown_system_message_is_a_noop(): void
    {
        $a = new RecordingChannelActor();

        $a->handle($this->actorContext(), new \stdClass(), 0);

        self::assertSame([], $a->events);
    }

    private function actorContext(): \Monadial\Nexus\Core\Actor\ActorContext
    {
        return $this->createStub(\Monadial\Nexus\Core\Actor\ActorContext::class);
    }
}
```

NOTE: if `ActorContext` is an interface, `createStub` works. If it's an abstract class, use `getMockBuilder()->getMock()` instead. Confirm with `grep -nE "^(interface|abstract class) ActorContext" packages/nexus-core/src/Actor/ActorContext.php`.

- [ ] **Step 4: Verify pass + lint**

```bash
docker exec -i nexus-php-1 composer dump-autoload
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketChannelActorTest.php 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: `OK (5 tests, ...)`; psalm + phpcs clean.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): WebSocketChannelActor abstract base"
```

---

## Task 11: `WebSocketDispatcher`

This is the central runtime piece. It owns the entry points runners call (`dispatchOpen`/`dispatchMessage`/`dispatchClose`), the PSR-11 + attribute-driven handler resolution, channel actor resolution, and connection-table maintenance.

**Files:**
- Create: `packages/nexus-http-ws/src/WebSocket/WebSocketDispatcher.php`
- Create: `packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketDispatcherTest.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorTest.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/Support/EchoHandler.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/Support/ArrayContainer.php`

- [ ] **Step 1: HandlerInstantiator failing test**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/Support/ArrayContainer.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ArrayContainer implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private readonly array $entries = [])
    {
    }

    public function get(string $id): mixed
    {
        if (!isset($this->entries[$id])) {
            throw new class ('No entry for ' . $id) extends RuntimeException implements NotFoundExceptionInterface {};
        }
        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }
}
```

Write `packages/nexus-http-ws/tests/Unit/WebSocket/Support/EchoHandler.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class EchoHandler extends WebSocketHandler
{
    public LoggerInterface $log;

    public function __construct(
        #[FromContext] public readonly WebSocketContext $ctx,
        ?LoggerInterface $log = null,
    ) {
        $this->log = $log ?? new NullLogger();
    }

    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }
}
```

Write `packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\ArrayContainer;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\EchoHandler;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\InMemoryWebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(HandlerInstantiator::class)]
final class HandlerInstantiatorTest extends TestCase
{
    #[Test]
    public function instantiates_handler_with_from_context_and_container_resolved_params(): void
    {
        $log = new NullLogger();
        $i = new HandlerInstantiator(new ArrayContainer([LoggerInterface::class => $log]));
        $ctx = new InMemoryWebSocketContext(1);

        $h = $i->instantiate(EchoHandler::class, $ctx);

        self::assertInstanceOf(EchoHandler::class, $h);
        self::assertSame($ctx, $h->ctx);
        self::assertSame($log, $h->log);
    }

    #[Test]
    public function rejects_from_context_on_wrong_param_type(): void
    {
        $bad = new class extends \Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler {
            public function __construct(
                #[\Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext] public readonly string $wrong = '',
            ) {}
            public function onMessage(\Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame $frame): void {}
        };

        $i = new HandlerInstantiator(new ArrayContainer());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FromContext');

        $i->instantiate($bad::class, new InMemoryWebSocketContext(1));
    }
}
```

- [ ] **Step 2: Verify failure**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorTest.php 2>&1 | tail -5
```

Expected: class not found.

- [ ] **Step 3: Implement HandlerInstantiator**

Write `packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * @psalm-api
 *
 * Reflection-driven instantiation of WebSocketHandler subclasses.
 * Walks constructor params: #[FromContext] resolves to the current context
 * (validated as WebSocketContext); everything else goes through PSR-11.
 *
 * #[FromActor] is left to the user's container layer to resolve via a
 * standard PSR-11 binding — this instantiator does not special-case it.
 */
final class HandlerInstantiator
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @param class-string<WebSocketHandler> $handlerClass
     */
    public function instantiate(string $handlerClass, WebSocketContext $ctx): WebSocketHandler
    {
        $rc = new ReflectionClass($handlerClass);
        $ctor = $rc->getConstructor();
        if ($ctor === null) {
            /** @var WebSocketHandler */
            return $rc->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $args[] = $this->resolveParam($param, $ctx, $handlerClass);
        }

        /** @var WebSocketHandler */
        return $rc->newInstanceArgs($args);
    }

    private function resolveParam(ReflectionParameter $param, WebSocketContext $ctx, string $handlerClass): mixed
    {
        $type = $param->getType();

        if (\count($param->getAttributes(FromContext::class)) > 0) {
            if (!$type instanceof ReflectionNamedType || $type->getName() !== WebSocketContext::class) {
                throw new RuntimeException(
                    "#[FromContext] on {$handlerClass}::__construct(\${$param->getName()}) requires "
                    . 'parameter type ' . WebSocketContext::class . '.',
                );
            }
            return $ctx;
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $id = $type->getName();
            if ($this->container->has($id)) {
                return $this->container->get($id);
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return null;
        }

        throw new RuntimeException(
            "Cannot resolve parameter \${$param->getName()} of {$handlerClass}::__construct.",
        );
    }
}
```

- [ ] **Step 4: Verify HandlerInstantiator tests pass**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorTest.php 2>&1 | tail -3
```

Expected: `OK (2 tests, 4 assertions)`.

- [ ] **Step 5: Failing tests for WebSocketDispatcher**

Write `packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketDispatcherTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Runtime\Test\TestRuntime;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\ArrayContainer;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\EchoHandler;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\InMemoryWebSocketContext;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\RecordingChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\InMemoryConnectionTable;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketDispatcher;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketDispatcher::class)]
final class WebSocketDispatcherTest extends TestCase
{
    #[Test]
    public function open_with_no_route_match_closes_with_1000(): void
    {
        $d = $this->dispatcher(WebSocketRouter::build([]));
        $ctx = new InMemoryWebSocketContext(1, '/missing');

        $d->dispatchOpen($ctx, new ServerRequest('GET', '/missing'));

        self::assertTrue($ctx->closed);
        self::assertSame(1000, $ctx->closeCode);
    }

    #[Test]
    public function handler_route_resolves_instantiates_and_invokes_open(): void
    {
        $router = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', EchoHandler::class)]);
        $table = new InMemoryConnectionTable();
        $d = $this->dispatcher($router, $table);
        $ctx = new InMemoryWebSocketContext(2, '/ws/echo');

        $d->dispatchOpen($ctx, new ServerRequest('GET', '/ws/echo'));

        self::assertTrue($table->has(2));
        $entry = $table->get(2);
        self::assertNotNull($entry);
        self::assertInstanceOf(EchoHandler::class, $entry['handler']);
    }

    #[Test]
    public function handler_route_message_dispatch_calls_on_message(): void
    {
        $router = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', EchoHandler::class)]);
        $d = $this->dispatcher($router);
        $ctx = new InMemoryWebSocketContext(3, '/ws/echo');
        $d->dispatchOpen($ctx, new ServerRequest('GET', '/ws/echo'));

        $d->dispatchMessage($ctx, new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'hi'));

        self::assertSame(['echo:hi'], $ctx->sentText);
    }

    #[Test]
    public function handler_route_close_removes_entry(): void
    {
        $router = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', EchoHandler::class)]);
        $table = new InMemoryConnectionTable();
        $d = $this->dispatcher($router, $table);
        $ctx = new InMemoryWebSocketContext(4, '/ws/echo');
        $d->dispatchOpen($ctx, new ServerRequest('GET', '/ws/echo'));

        $d->dispatchClose($ctx, 1001);

        self::assertFalse($table->has(4));
    }

    #[Test]
    public function unknown_fd_message_is_silently_dropped(): void
    {
        $d = $this->dispatcher(WebSocketRouter::build([]));
        $ctx = new InMemoryWebSocketContext(99);

        $d->dispatchMessage($ctx, new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'x'));

        self::assertSame([], $ctx->sentText);
    }

    private function dispatcher(WebSocketRouter $router, ?InMemoryConnectionTable $table = null): WebSocketDispatcher
    {
        $system = ActorSystem::create('t', new TestRuntime());
        return new WebSocketDispatcher(
            $router,
            $table ?? new InMemoryConnectionTable(),
            new ChannelActorRegistry($system),
            new HandlerInstantiator(new ArrayContainer()),
            $system,
        );
    }
}
```

- [ ] **Step 6: Verify failure**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketDispatcherTest.php 2>&1 | tail -5
```

Expected: class not found.

- [ ] **Step 7: Implement WebSocketDispatcher**

Write `packages/nexus-http-ws/src/WebSocket/WebSocketDispatcher.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelMessageReceived;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @psalm-api
 *
 * Runtime-agnostic dispatcher. Runners call dispatchOpen/Message/Close
 * for every WebSocket lifecycle event. The dispatcher routes via the
 * WebSocketRouter, instantiates handlers via the HandlerInstantiator,
 * resolves channel actors via the registry, and maintains the
 * ConnectionTable.
 */
final class WebSocketDispatcher
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly WebSocketRouter $router,
        private readonly ConnectionTable $table,
        private readonly ChannelActorRegistry $registry,
        private readonly HandlerInstantiator $instantiator,
        private readonly ActorSystem $system,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function dispatchOpen(WebSocketContext $ctx, ServerRequestInterface $upgrade): void
    {
        $match = $this->router->match($upgrade->getUri()->getPath());
        if ($match === null) {
            $ctx->close(1000, 'No WebSocket route');
            return;
        }

        $route = $match['route'];

        try {
            if ($route->mode === WebSocketRoute::MODE_HANDLER) {
                /** @var class-string<WebSocketHandler> $handlerClass */
                $handlerClass = $route->targetClass;
                $handler = $this->instantiator->instantiate($handlerClass, $ctx);
                $handler->onOpen();
                $this->table->attachHandler($ctx->id(), $handler, $ctx);
                return;
            }

            if ($route->mode === WebSocketRoute::MODE_CHANNEL) {
                /** @var class-string<WebSocketChannelActor> $actorClass */
                $actorClass = $route->targetClass;
                $keyFrom = $route->keyFrom ?? '';
                $key = $match['params'][$keyFrom] ?? '';
                $name = ChannelActorNameResolver::resolve($key);

                $ref = $this->registry->resolveOrSpawn(
                    $name,
                    Props::fromFactory(static fn() => new $actorClass()),
                );
                $ref->tell(new ChannelConnectionOpened($ctx->id(), $ctx, $upgrade));
                $this->table->attachChannel($ctx->id(), $ref, $name, $ctx);
                return;
            }

            throw new \RuntimeException("Unknown WebSocket route mode: {$route->mode}");
        } catch (Throwable $e) {
            $this->logger->error('WebSocket open dispatch failed', ['exception' => $e]);
            $ctx->close(1011, 'Server error');
        }
    }

    public function dispatchMessage(WebSocketContext $ctx, WebSocketFrame $frame): void
    {
        $entry = $this->table->get($ctx->id());
        if ($entry === null) {
            return;
        }
        try {
            if ($entry['handler'] !== null) {
                $entry['handler']->onMessage($frame);
                return;
            }
            if ($entry['channelActor'] !== null) {
                $entry['channelActor']->tell(new ChannelMessageReceived($ctx->id(), $frame));
            }
        } catch (Throwable $e) {
            $this->logger->error('WebSocket message dispatch failed', ['exception' => $e]);
        }
    }

    public function dispatchClose(WebSocketContext $ctx, int $code): void
    {
        $entry = $this->table->get($ctx->id());
        if ($entry === null) {
            return;
        }
        try {
            if ($entry['handler'] !== null) {
                $entry['handler']->onClose($code);
            } elseif ($entry['channelActor'] !== null) {
                $entry['channelActor']->tell(new ChannelConnectionClosed($ctx->id(), $code));
            }
        } catch (Throwable $e) {
            $this->logger->error('WebSocket close dispatch failed', ['exception' => $e]);
        } finally {
            $this->table->remove($ctx->id());
        }
    }
}
```

NOTE: confirm `Props::fromFactory` signature with `grep -nE "public static function fromFactory" packages/nexus-core/src/Actor/Props.php`. If it requires `class-string` or specific return-type generics, add a `@psalm-suppress` for `MixedArgument` or wrap accordingly.

- [ ] **Step 8: Verify dispatcher tests pass + lint**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/WebSocketDispatcherTest.php 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: `OK (5 tests, ...)`; psalm + phpcs clean.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): HandlerInstantiator + WebSocketDispatcher"
```

---

## Task 12: `WsApplication` + `CompiledWsApplication`

**Files:**
- Create: `packages/nexus-http-ws/src/CompiledWsApplication.php`
- Create: `packages/nexus-http-ws/src/WsApplication.php`
- Create: `packages/nexus-http-ws/tests/Unit/CompiledWsApplicationTest.php`
- Create: `packages/nexus-http-ws/tests/Unit/WsApplicationTest.php`

- [ ] **Step 1: Implement CompiledWsApplication**

Write `packages/nexus-http-ws/src/CompiledWsApplication.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws;

use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketDispatcher;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final class CompiledWsApplication implements CompiledApplication
{
    public function __construct(
        private readonly CompiledHttpApp $http,
        private readonly WebSocketRouter $router,
        private readonly WebSocketDispatcher $dispatcher,
        private readonly ContainerInterface $container,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->http->handle($request);
    }

    public function hasWebSocketRoutes(): bool
    {
        return $this->router->routes() !== [];
    }

    public function webSocketRouter(): WebSocketRouter
    {
        return $this->router;
    }

    public function dispatcher(): WebSocketDispatcher
    {
        return $this->dispatcher;
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }
}
```

- [ ] **Step 2: Failing test for CompiledWsApplication**

Write `packages/nexus-http-ws/tests/Unit/CompiledWsApplicationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Runtime\Test\TestRuntime;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\ArrayContainer;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\EchoHandler;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\InMemoryConnectionTable;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketDispatcher;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledWsApplication::class)]
final class CompiledWsApplicationTest extends TestCase
{
    #[Test]
    public function has_web_socket_routes_reflects_router_state(): void
    {
        self::assertFalse($this->build([])->hasWebSocketRoutes());
        self::assertTrue($this->build([WebSocketRoute::handler('/ws/echo', EchoHandler::class)])->hasWebSocketRoutes());
    }

    #[Test]
    public function handle_delegates_to_compiled_http_app(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $http = HttpApp::create($system);
        $http->get('/ping', static fn() => Response::ok('pong'));
        $container = new ArrayContainer();
        $router = WebSocketRouter::build([]);
        $table = new InMemoryConnectionTable();
        $c = new CompiledWsApplication(
            $http->compile(),
            $router,
            new WebSocketDispatcher($router, $table, new ChannelActorRegistry($system), new HandlerInstantiator($container), $system),
            $container,
        );

        $resp = $c->handle(new ServerRequest('GET', '/ping'));

        self::assertSame(200, $resp->getStatusCode());
    }

    /** @param list<WebSocketRoute> $routes */
    private function build(array $routes): CompiledWsApplication
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $http = HttpApp::create($system);
        $container = new ArrayContainer();
        $router = WebSocketRouter::build($routes);
        $table = new InMemoryConnectionTable();
        return new CompiledWsApplication(
            $http->compile(),
            $router,
            new WebSocketDispatcher($router, $table, new ChannelActorRegistry($system), new HandlerInstantiator($container), $system),
            $container,
        );
    }
}
```

- [ ] **Step 3: Verify pass**

```bash
docker exec -i nexus-php-1 composer dump-autoload
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/CompiledWsApplicationTest.php 2>&1 | tail -3
```

Expected: `OK (2 tests, 3 assertions)`.

- [ ] **Step 4: Implement WsApplication**

Write `packages/nexus-http-ws/src/WsApplication.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\ActorRegistration;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\App\PoolSingletonSpawner;
use Monadial\Nexus\Http\Dsl\RouteBuilder;
use Monadial\Nexus\Http\Dsl\RouteGroup;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\DuplicateRouteException;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\InMemoryConnectionTable;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketDispatcher;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use Monadial\Nexus\Serialization\MessageSerializer;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Decorates any Application (typically an HttpApplication) and adds the
 * WebSocket DSL. Compiles to CompiledWsApplication.
 */
final class WsApplication implements Application
{
    /** @var list<WebSocketRoute> */
    private array $wsRoutes = [];

    /** @var Closure(Throwable, WebSocketContext): void|null */
    private ?Closure $wsExceptionMapper = null;

    private ?ContainerInterface $container = null;

    private readonly ActorSystem $system;

    private function __construct(
        private readonly Application $inner,
        ActorSystem $system,
    ) {
        $this->system = $system;
    }

    public static function decorate(Application $inner, ActorSystem $system): self
    {
        return new self($inner, $system);
    }

    public static function create(ActorSystem $system): self
    {
        return new self(HttpApplication::create($system), $system);
    }

    public function withContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        return $this;
    }

    public function inner(): Application
    {
        return $this->inner;
    }

    // Application surface — delegate everything, return $this so chains stay on WsApplication.

    public function get(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->get($path, $handler);
    }

    public function post(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->post($path, $handler);
    }

    public function put(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->put($path, $handler);
    }

    public function patch(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->patch($path, $handler);
    }

    public function delete(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->delete($path, $handler);
    }

    public function group(string $prefix, Closure $register): RouteGroup
    {
        return $this->inner->group($prefix, $register);
    }

    public function middleware(string|MiddlewareInterface $middleware): self
    {
        $this->inner->middleware($middleware);
        return $this;
    }

    public function actor(string $name, Props $props): ActorRegistration
    {
        return $this->inner->actor($name, $props);
    }

    public function perRequestActor(string $name, Props $props): ActorRegistration
    {
        return $this->inner->perRequestActor($name, $props);
    }

    public function discover(string $directory): self
    {
        $this->inner->discover($directory);
        return $this;
    }

    public function errorMode(ErrorMode $mode): self
    {
        $this->inner->errorMode($mode);
        return $this;
    }

    public function onException(string $exceptionClass, Closure $mapper): self
    {
        $this->inner->onException($exceptionClass, $mapper);
        return $this;
    }

    public function withPoolSingletonSpawner(PoolSingletonSpawner $spawner): self
    {
        $this->inner->withPoolSingletonSpawner($spawner);
        return $this;
    }

    public function withMessageSerializer(MessageSerializer $serializer): self
    {
        $this->inner->withMessageSerializer($serializer);
        return $this;
    }

    public function withRouteCache(CacheInterface $cache, ?string $key = null): self
    {
        $this->inner->withRouteCache($cache, $key);
        return $this;
    }

    public function withoutDefaultExceptionHandler(): self
    {
        $this->inner->withoutDefaultExceptionHandler();
        return $this;
    }

    public function clearRouteCache(): void
    {
        $this->inner->clearRouteCache();
    }

    // WebSocket DSL.

    /** @param class-string<WebSocketHandler> $handlerClass */
    public function ws(string $path, string $handlerClass): self
    {
        $this->guardDuplicate($path);
        $this->wsRoutes[] = WebSocketRoute::handler($path, $handlerClass);
        return $this;
    }

    /** @param class-string<WebSocketChannelActor> $actorClass */
    public function channel(string $path, string $actorClass, string $key): self
    {
        if ($key === '') {
            throw new \InvalidArgumentException(
                "WsApplication::channel('{$path}') requires a non-empty key parameter.",
            );
        }
        $this->guardDuplicate($path);
        $this->wsRoutes[] = WebSocketRoute::channel($path, $actorClass, $key);
        return $this;
    }

    /** @param Closure(Throwable, WebSocketContext): void $mapper */
    public function onWebSocketException(Closure $mapper): self
    {
        $this->wsExceptionMapper = $mapper;
        return $this;
    }

    public function compile(): CompiledWsApplication
    {
        $compiledHttp = $this->compileInner();
        $router = WebSocketRouter::build($this->wsRoutes);
        $container = $this->container ?? new EmptyContainer();
        $table = new InMemoryConnectionTable();
        $dispatcher = new WebSocketDispatcher(
            $router,
            $table,
            new ChannelActorRegistry($this->system),
            new HandlerInstantiator($container),
            $this->system,
        );
        return new CompiledWsApplication($compiledHttp, $router, $dispatcher, $container);
    }

    private function compileInner(): \Monadial\Nexus\Http\App\CompiledHttpApp
    {
        $compiled = $this->inner->compile();
        if ($compiled instanceof CompiledHttpApplication) {
            return $compiled->inner();
        }
        if ($compiled instanceof CompiledWsApplication) {
            // Nested decoration (rare). Reach the underlying CompiledHttpApp via reflection-free getter
            // by recompiling the wrapped Application's HttpApplication path.
            throw new \RuntimeException(
                'WsApplication cannot decorate another WsApplication. Decorate an HttpApplication instead.',
            );
        }
        throw new \RuntimeException('Unsupported inner CompiledApplication type: ' . $compiled::class);
    }

    private function guardDuplicate(string $path): void
    {
        foreach ($this->wsRoutes as $r) {
            if ($r->path === $path) {
                throw new DuplicateRouteException("WebSocket route '{$path}' already registered.");
            }
        }
    }
}
```

Also add a tiny empty PSR-11 container as a default:

Write `packages/nexus-http-ws/src/EmptyContainer.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * @internal Default container used by WsApplication when none is provided.
 * Returns nothing; HandlerInstantiator falls through to default values or
 * throws for required parameters that aren't FromContext-marked.
 */
final class EmptyContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new class ("Empty container has no entry for {$id}.") extends RuntimeException implements NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return false;
    }
}
```

- [ ] **Step 5: Failing test for WsApplication**

Write `packages/nexus-http-ws/tests/Unit/WsApplicationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Ws\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Runtime\Test\TestRuntime;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\EchoHandler;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\RecordingChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\DuplicateRouteException;
use Monadial\Nexus\Http\Ws\WsApplication;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WsApplication::class)]
final class WsApplicationTest extends TestCase
{
    #[Test]
    public function create_shortcut_wraps_a_fresh_http_application(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        self::assertInstanceOf(HttpApplication::class, $app->inner());
    }

    #[Test]
    public function http_method_delegation_works_and_request_handles(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->get('/hello', static fn() => Response::ok('world'));

        $resp = $app->compile()->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('world', (string) $resp->getBody());
    }

    #[Test]
    public function ws_registers_handler_route(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->ws('/ws/echo', EchoHandler::class);

        $compiled = $app->compile();
        self::assertTrue($compiled->hasWebSocketRoutes());
        self::assertNotNull($compiled->webSocketRouter()->match('/ws/echo'));
    }

    #[Test]
    public function channel_registers_channel_route(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->channel('/ws/room/{id}', RecordingChannelActor::class, 'id');

        $compiled = $app->compile();
        self::assertTrue($compiled->hasWebSocketRoutes());
        $m = $compiled->webSocketRouter()->match('/ws/room/lobby');
        self::assertSame(['id' => 'lobby'], $m['params'] ?? null);
    }

    #[Test]
    public function channel_rejects_empty_key(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));

        $this->expectException(\InvalidArgumentException::class);
        $app->channel('/ws/x/{id}', RecordingChannelActor::class, '');
    }

    #[Test]
    public function duplicate_path_throws(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        $app->ws('/ws/echo', EchoHandler::class);

        $this->expectException(DuplicateRouteException::class);
        $app->ws('/ws/echo', EchoHandler::class);
    }

    #[Test]
    public function compile_returns_compiled_ws_application(): void
    {
        $app = WsApplication::create(ActorSystem::create('t', new TestRuntime()));
        self::assertInstanceOf(CompiledWsApplication::class, $app->compile());
    }

    #[Test]
    public function decorate_wraps_pre_configured_http_application(): void
    {
        $http = HttpApplication::create(ActorSystem::create('t', new TestRuntime()));
        $http->get('/api/health', static fn() => Response::ok('ok'));
        $app = WsApplication::decorate($http, ActorSystem::create('t', new TestRuntime()));

        $resp = $app->compile()->handle(new ServerRequest('GET', '/api/health'));

        self::assertSame(200, $resp->getStatusCode());
    }
}
```

- [ ] **Step 6: Verify pass + lint**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WsApplicationTest.php packages/nexus-http-ws/tests/Unit/CompiledWsApplicationTest.php 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-ws/src 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-ws/src 2>&1 | tail -3
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "feat(http-ws): WsApplication + CompiledWsApplication"
```

---

## Task 13: Run the full nexus-http-ws test suite + spec self-review against tests

Sanity check that the new package is internally consistent before touching runners.

- [ ] **Step 1: Run full unit testsuite for nexus-http-ws**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit 2>&1 | tail -5
```

Expected: all tests green, no skips.

- [ ] **Step 2: Full repo psalm + phpcs + cs-fixer + deptrac**

```bash
docker exec -i nexus-php-1 vendor/bin/psalm 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/php-cs-fixer fix --dry-run 2>&1 | tail -5
docker exec -i nexus-php-1 php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse 2>&1 | tail -5
```

Expected: psalm clean, phpcs clean, cs-fixer reports 0 fixable, deptrac 0 violations.

- [ ] **Step 3: Method coverage gate for nexus-http-ws**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit --coverage-text --colors=never 2>&1 | tail -25
```

Expected: nexus-http-ws src/ files at ≥90% method coverage. If any file is below, add the missing test cases inline before continuing.

- [ ] **Step 4: Commit (if any fixes were needed)**

If the previous step produced any fixes, commit:
```bash
git add packages/nexus-http-ws/
git -c commit.gpgsign=false commit -m "chore(http-ws): coverage gap fixes"
```

Otherwise skip.

---

## Task 14: Rewrite `SwooleWorkerServer` (worker runner)

The worker runner is rewritten from scratch. The new file replaces the current `SwooleWorkerHttpServer.php` (renamed). Today's file holds: HTTP request wiring, WebSocket Open/Message/Close wiring, ConnectionTable management, ChannelActorRegistry resolution, handler factory invocation, channel actor spawning. All of that now lives in `nexus-http-ws`. The runner's job is reduced to: build a per-worker `CompiledApplication`, wire Swoole's `Request` to `$app->handle()`, wire `Open/Message/Close` (only when `hasWebSocketRoutes()`) to the dispatcher.

**Files:**
- Modify: `packages/nexus-http-server-swoole/composer.json` — add `nexus-actors/http-ws` dep
- Create: `packages/nexus-http-server-swoole/src/Server/SwooleConnectionContext.php` (renamed-and-moved `LocalWebSocketContext`)
- Create: `packages/nexus-http-server-swoole/src/Server/SwooleWorkerServer.php` (replaces `SwooleWorkerHttpServer.php`)
- Modify: `packages/nexus-http-server-swoole/src/Server/WorkerServerRuntime.php` — drop the WS-specific fields (channels, connections); keep system/app/failureBucket
- Modify: `packages/nexus-http-server-swoole/src/Server/SwooleWorkerConfig.php` — verify `enableWebSocket` is still here (still gates Server type)
- Modify: `packages/nexus-http-server-swoole/src/Server/SwooleHttpServerAdapter.php` — update to call `SwooleWorkerServer::run` if it currently calls the old name

NOTE: Task 19 deletes the OLD files (`SwooleWorkerHttpServer.php`, the old `WebSocket/*` tree under this package, `App/SwooleHttpApp.php`, `App/SwooleCompiledHttpApp.php`). Do NOT delete them in this task — we keep them temporarily so the codebase still compiles while runner-mode tests are being migrated.

- [ ] **Step 1: Add http-ws dep to worker package composer.json**

In `packages/nexus-http-server-swoole/composer.json`, find the `require` block and add:

```json
"nexus-actors/http-ws": "self.version",
```

Run:
```bash
docker exec -i nexus-php-1 composer dump-autoload
```

- [ ] **Step 2: Move LocalWebSocketContext → SwooleConnectionContext**

Read `packages/nexus-http-server-swoole/src/WebSocket/LocalWebSocketContext.php` to get the existing implementation. Create `packages/nexus-http-server-swoole/src/Server/SwooleConnectionContext.php` with the same body, renamed class and changed namespace + interface reference:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\WebSocket\Server as WebSocketServer;

use const WEBSOCKET_OPCODE_BINARY;
use const WEBSOCKET_OPCODE_PING;

/**
 * @psalm-api
 *
 * Worker-mode WebSocketContext — pushes directly to the local Swoole
 * WebSocket server. One instance per connection.
 */
final class SwooleConnectionContext implements WebSocketContext
{
    public function __construct(
        private readonly WebSocketServer $server,
        private readonly int $fd,
        private readonly ServerRequestInterface $request,
    ) {
    }

    public function id(): int
    {
        return $this->fd;
    }

    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    public function send(string $text): void
    {
        $this->server->push($this->fd, $text);
    }

    public function sendBinary(string $data): void
    {
        $this->server->push($this->fd, $data, WEBSOCKET_OPCODE_BINARY);
    }

    public function sendPing(): void
    {
        $this->server->push($this->fd, '', WEBSOCKET_OPCODE_PING);
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->server->disconnect($this->fd, $code, $reason);
    }

    public function isAlive(): bool
    {
        return $this->server->exist($this->fd);
    }
}
```

- [ ] **Step 3: Slim WorkerServerRuntime**

Edit `packages/nexus-http-server-swoole/src/Server/WorkerServerRuntime.php` so it now holds only:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Ws\CompiledApplication;

/**
 * @internal Per-worker-process runtime state for SwooleWorkerServer.
 */
final class WorkerServerRuntime
{
    public ?ActorSystem $system = null;

    public ?CompiledApplication $app = null;

    /** @var array{count: int, since: float} */
    public array $failureBucket = ['count' => 0, 'since' => 0.0];

    public function reset(): void
    {
        $this->system = null;
        $this->app = null;
    }
}
```

- [ ] **Step 4: Implement SwooleWorkerServer**

Write `packages/nexus-http-server-swoole/src/Server/SwooleWorkerServer.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

use function microtime;

/**
 * @psalm-api
 *
 * Worker-mode HTTP+WebSocket runner. Builds a per-worker
 * CompiledApplication via the user-supplied factory; wires Swoole
 * Request to handle(), and (if hasWebSocketRoutes) Open/Message/Close
 * to the dispatcher.
 */
final class SwooleWorkerServer
{
    /** @param Closure(ActorSystem): CompiledApplication $factory */
    public static function run(SwooleWorkerConfig $config, Closure $factory): void
    {
        $runtime = new WorkerServerRuntime();
        $server = $config->enableWebSocket
            ? new WebSocketServer($config->host, $config->port)
            : new HttpServer($config->host, $config->port);

        $settings = [
            'dispatch_mode' => $config->dispatchMode,
            'max_conn' => $config->maxConn,
            'max_request' => $config->maxRequest,
            'reactor_num' => $config->reactorThreads,
            'worker_num' => $config->workers,
        ];
        if ($config->logFile !== '') {
            $settings['log_file'] = $config->logFile;
        }
        $server->set($settings);

        $enableWebSocket = $config->enableWebSocket;

        $server->on('WorkerStart', static function (HttpServer|WebSocketServer $s, int $workerId) use ($factory, $config, $runtime): void {
            try {
                $system = ActorSystem::create("http-worker-{$workerId}", new SwooleRuntime());
                $app = $factory($system);
                $runtime->system = $system;
                $runtime->app = $app;
            } catch (Throwable $e) {
                $config->logger->error('HTTP factory failed during WorkerStart', ['exception' => $e, 'workerId' => $workerId]);
                self::recordFailureAndMaybeShutdown($s, $config, $runtime);
            }
        });

        $server->on('Request', static function (Request $req, Response $res) use ($config, $runtime): void {
            try {
                $app = $runtime->app;
                if ($app === null) {
                    $res->status(503);
                    $res->end('Service not ready');
                    return;
                }
                $psr7 = SwooleRequestTranslator::toPsr7($req);
                SwooleResponseWriter::write($app->handle($psr7), $res);
            } catch (Throwable $e) {
                $config->logger->error('Request handling failed', ['exception' => $e]);
                if (!$res->isWritable()) {
                    return;
                }
                $res->status(500);
                $res->end('Internal Server Error');
            }
        });

        if ($enableWebSocket) {
            $server->on('Open', static function (WebSocketServer $s, Request $req) use ($config, $runtime): void {
                try {
                    $app = $runtime->app;
                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }
                    $psr7 = SwooleRequestTranslator::toPsr7($req);
                    $ctx = new SwooleConnectionContext($s, (int) $req->fd, $psr7);
                    $app->dispatcher()->dispatchOpen($ctx, $psr7);
                } catch (Throwable $e) {
                    $config->logger->error('WebSocket Open failed', ['exception' => $e]);
                    $s->disconnect((int) $req->fd, 1011, 'Server error');
                }
            });

            $server->on('Message', static function (WebSocketServer $s, SwooleFrame $frame) use ($config, $runtime): void {
                try {
                    $app = $runtime->app;
                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }
                    $kind = (int) $frame->opcode === 2 ? WebSocketFrame::KIND_BINARY : WebSocketFrame::KIND_TEXT;
                    $wsFrame = new WebSocketFrame($kind, (string) $frame->data);
                    // Reconstruct a minimal context — for Message/Close we can build a fresh wrapper
                    // because the dispatcher looks up state by fd in the connection table.
                    $ctx = new SwooleConnectionContext($s, (int) $frame->fd, new \Nyholm\Psr7\ServerRequest('GET', '/'));
                    $app->dispatcher()->dispatchMessage($ctx, $wsFrame);
                } catch (Throwable $e) {
                    $config->logger->error('WebSocket Message failed', ['exception' => $e]);
                }
            });

            $server->on('Close', static function (WebSocketServer $s, int $fd) use ($config, $runtime): void {
                try {
                    $app = $runtime->app;
                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }
                    $ctx = new SwooleConnectionContext($s, $fd, new \Nyholm\Psr7\ServerRequest('GET', '/'));
                    $app->dispatcher()->dispatchClose($ctx, 1000);
                } catch (Throwable $e) {
                    $config->logger->error('WebSocket Close failed', ['exception' => $e]);
                }
            });
        }

        $server->on('WorkerStop', static function (HttpServer|WebSocketServer $s, int $workerId) use ($config, $runtime): void {
            $system = $runtime->system;
            if ($system !== null) {
                try {
                    $system->shutdown($config->shutdownTimeout);
                } catch (Throwable $e) {
                    $config->logger->error('System shutdown failed in WorkerStop', ['exception' => $e, 'workerId' => $workerId]);
                }
            }
            $runtime->reset();
        });

        if ($config->installSignalHandlers) {
            ShutdownSignalHandler::install($server, $config->logger);
        }

        $server->start();
    }

    private static function recordFailureAndMaybeShutdown(
        HttpServer|WebSocketServer $server,
        SwooleWorkerConfig $config,
        WorkerServerRuntime $runtime,
    ): void {
        $now = microtime(true);
        $bucket = $runtime->failureBucket;
        if ($bucket['since'] === 0.0 || $now - $bucket['since'] > 5.0) {
            $bucket = ['count' => 1, 'since' => $now];
        } else {
            $bucket['count']++;
        }
        $runtime->failureBucket = $bucket;
        if ($bucket['count'] >= 3) {
            $config->logger->error('HTTP factory failed during worker boot 3 times in 5s — shutting down master.');
            $server->shutdown();
        }
    }
}
```

NOTE: the Message/Close handlers build a "minimal" `SwooleConnectionContext` carrying an empty `ServerRequest`. That's deliberate — the dispatcher only uses `$ctx->id()` to look up state in the connection table for non-Open events. The cached `WebSocketContext` returned by `ConnectionTable::get($fd)['ctx']` is what handlers/channel actors actually hold and send on. The fresh wrapper exists only so the dispatcher receives a `WebSocketContext` argument.

- [ ] **Step 5: Update `SwooleHttpServerAdapter` if needed**

Check if `packages/nexus-http-server-swoole/src/Server/SwooleHttpServerAdapter.php` references the old `SwooleWorkerHttpServer::run`. If yes, switch it to `SwooleWorkerServer::run`. Run:
```bash
docker exec -i nexus-php-1 grep -n "SwooleWorkerHttpServer" packages/nexus-http-server-swoole/src/Server/SwooleHttpServerAdapter.php
```

If output appears, edit the file to use `SwooleWorkerServer` instead. The factory signature passed to the adapter must produce a `CompiledApplication`; the adapter today produces a `CompiledHttpApp` directly, so wrap with `new CompiledHttpApplication(...)` from `Monadial\Nexus\Http\Ws`.

- [ ] **Step 6: Run psalm + phpcs on the package**

```bash
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-server-swoole/src 2>&1 | tail -5
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-server-swoole/src 2>&1 | tail -5
```

If psalm complains about unused old files (`SwooleHttpApp.php` etc), those are fine for now — Task 19 deletes them. If it complains about the new file, fix.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-http-server-swoole/
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): rewrite worker runner around nexus-http-ws

Adds SwooleWorkerServer + SwooleConnectionContext. Slims
WorkerServerRuntime. Old SwooleWorkerHttpServer/SwooleHttpApp/
WebSocket/* still present, deleted in a later task."
```

---

## Task 15: Rewrite `SwooleThreadServer` (thread runner)

Same scope reduction as Task 14, plus the thread-mode-specific extras: shared `Thread\Map` directory + `Thread\Queue` array from `init_arguments`; per-thread `WorkerNode`; rejection of channel routes; restart-loop protection; cross-thread frame push wiring.

**Files:**
- Modify: `packages/nexus-http-server-swoole-threads/composer.json` — add `nexus-actors/http-ws` dep
- Create: `packages/nexus-http-server-swoole-threads/src/Server/ThreadAwareConnectionContext.php` (renamed-and-moved)
- Create: `packages/nexus-http-server-swoole-threads/src/Server/SwooleThreadServer.php` (replaces `SwooleThreadHttpServer.php`)
- Modify: `packages/nexus-http-server-swoole-threads/src/Server/ThreadServerRuntime.php` — drop WS-specific fields (channels, connections, routerSenders, threadId); keep system/app/failureBucket
- KEEP unchanged: `WebSocketFramePush` message — stays in this package as runtime-specific

NOTE: same as Task 14, leave the old files in place; Task 19 deletes them.

- [ ] **Step 1: Add http-ws dep**

In `packages/nexus-http-server-swoole-threads/composer.json` `require` add:
```json
"nexus-actors/http-ws": "self.version",
```

Run `docker exec -i nexus-php-1 composer dump-autoload`.

- [ ] **Step 2: Move ThreadAwareWebSocketContext → ThreadAwareConnectionContext**

Read the existing `packages/nexus-http-server-swoole-threads/src/WebSocket/ThreadAwareWebSocketContext.php`. Create `packages/nexus-http-server-swoole-threads/src/Server/ThreadAwareConnectionContext.php` with the SAME body and behavior, but:
- Rename class to `ThreadAwareConnectionContext`
- Change namespace to `Monadial\Nexus\Http\Server\Swoole\Threads\Server`
- Replace `use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;` with `use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;`

- [ ] **Step 3: Slim ThreadServerRuntime**

Edit `packages/nexus-http-server-swoole-threads/src/Server/ThreadServerRuntime.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Ws\CompiledApplication;

/**
 * @internal Per-worker-thread runtime state for SwooleThreadServer.
 */
final class ThreadServerRuntime
{
    public ?ActorSystem $system = null;

    public ?CompiledApplication $app = null;

    /** @var array{count: int, since: float} */
    public array $failureBucket = ['count' => 0, 'since' => 0.0];

    public function reset(): void
    {
        $this->system = null;
        $this->app = null;
    }
}
```

- [ ] **Step 4: Implement SwooleThreadServer**

Write `packages/nexus-http-server-swoole-threads/src/Server/SwooleThreadServer.php`:

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as HttpServer;
use Swoole\Thread;
use Swoole\Thread\ArrayList;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

use function microtime;

/**
 * @psalm-api
 *
 * Thread-mode HTTP+WebSocket runner using Swoole 6's native SWOOLE_THREAD
 * server. Per-thread ActorSystem + WorkerNode; shared Thread\Map +
 * Thread\Queue array allocated in init_arguments. WebSocket support is
 * conditional on $config->enableWebSocket and $app->hasWebSocketRoutes().
 * Channel-mode routes are rejected at boot (their state isn't
 * serialization-safe across threads).
 */
final class SwooleThreadServer
{
    /** @param Closure(ActorSystem, WorkerNode): CompiledApplication $factory */
    public static function run(SwooleThreadConfig $config, Closure $factory): void
    {
        $threads = $config->threads;
        $enableWebSocket = $config->enableWebSocket;
        $runtime = new ThreadServerRuntime();
        $server = $enableWebSocket
            ? new WebSocketServer($config->host, $config->port, SWOOLE_THREAD, SWOOLE_SOCK_TCP)
            : new HttpServer($config->host, $config->port, SWOOLE_THREAD, SWOOLE_SOCK_TCP);

        $settings = [
            'max_request' => $config->maxRequest,
            'worker_num' => $threads,
            /**
             * Thread\ArrayList stubs constrain offsetSet to ArrayAccess values;
             * Swoole 6 actually accepts any thread-safe type including Queue.
             *
             * @psalm-suppress InvalidArgument
             */
            'init_arguments' => static function () use ($threads): array {
                $directory = new Map();
                $queues = new ArrayList();
                for ($i = 0; $i < $threads; $i++) {
                    $queues[] = new Queue();
                }
                return [$directory, $queues, $threads];
            },
        ];
        $server->set($settings);

        $server->on('WorkerStart', static function (HttpServer|WebSocketServer $s, int $workerId) use ($factory, $config, $runtime, $enableWebSocket): void {
            try {
                /** @var array{0: Map, 1: ArrayList, 2: int} $args */
                $args = Thread::getArguments();
                $directory = $args[0];
                $queueList = $args[1];
                $totalThreads = $args[2];

                /** @var array<int, Queue> $queues */
                $queues = [];
                for ($i = 0; $i < $totalThreads; $i++) {
                    /** @psalm-suppress InvalidArgument @var Queue $q */
                    $q = $queueList[$i];
                    $queues[$i] = $q;
                }

                $ring = new ConsistentHashRing($totalThreads);
                $system = ActorSystem::create("http-thread-{$workerId}", new SwooleRuntime());
                $node = new WorkerNode(
                    $workerId,
                    $system,
                    new ThreadQueueTransport($queues, $workerId),
                    $ring,
                    new ThreadMapDirectory($directory),
                );
                $node->start();

                $app = $factory($system, $node);

                if ($enableWebSocket && $app instanceof CompiledWsApplication) {
                    // Channel routes rejected at boot — would silently degrade to thread-local semantics.
                    $app->webSocketRouter()->assertNoChannelRoutes();
                }

                $runtime->system = $system;
                $runtime->app = $app;
            } catch (Throwable $e) {
                $config->logger->error('HTTP factory failed during WorkerStart', ['exception' => $e, 'workerId' => $workerId]);
                self::recordFailureAndMaybeShutdown($s, $config, $runtime);
            }
        });

        $server->on('Request', static function (Request $req, Response $res) use ($config, $runtime): void {
            try {
                $app = $runtime->app;
                if ($app === null) {
                    $res->status(503);
                    $res->end('Service not ready');
                    return;
                }
                $psr7 = SwooleRequestTranslator::toPsr7($req);
                SwooleResponseWriter::write($app->handle($psr7), $res);
            } catch (Throwable $e) {
                $config->logger->error('Request handling failed', ['exception' => $e]);
                if (!$res->isWritable()) {
                    return;
                }
                $res->status(500);
                $res->end('Internal Server Error');
            }
        });

        if ($enableWebSocket) {
            $server->on('Open', static function (WebSocketServer $s, Request $req) use ($config, $runtime): void {
                try {
                    $app = $runtime->app;
                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }
                    $psr7 = SwooleRequestTranslator::toPsr7($req);
                    $ctx = new ThreadAwareConnectionContext($s, (int) $req->fd, $psr7);
                    $app->dispatcher()->dispatchOpen($ctx, $psr7);
                } catch (Throwable $e) {
                    $config->logger->error('WebSocket Open failed', ['exception' => $e]);
                    $s->disconnect((int) $req->fd, 1011, 'Server error');
                }
            });

            $server->on('Message', static function (WebSocketServer $s, SwooleFrame $frame) use ($config, $runtime): void {
                try {
                    $app = $runtime->app;
                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }
                    $kind = (int) $frame->opcode === 2 ? WebSocketFrame::KIND_BINARY : WebSocketFrame::KIND_TEXT;
                    $wsFrame = new WebSocketFrame($kind, (string) $frame->data);
                    $ctx = new ThreadAwareConnectionContext($s, (int) $frame->fd, new \Nyholm\Psr7\ServerRequest('GET', '/'));
                    $app->dispatcher()->dispatchMessage($ctx, $wsFrame);
                } catch (Throwable $e) {
                    $config->logger->error('WebSocket Message failed', ['exception' => $e]);
                }
            });

            $server->on('Close', static function (WebSocketServer $s, int $fd) use ($config, $runtime): void {
                try {
                    $app = $runtime->app;
                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }
                    $ctx = new ThreadAwareConnectionContext($s, $fd, new \Nyholm\Psr7\ServerRequest('GET', '/'));
                    $app->dispatcher()->dispatchClose($ctx, 1000);
                } catch (Throwable $e) {
                    $config->logger->error('WebSocket Close failed', ['exception' => $e]);
                }
            });
        }

        $server->on('WorkerStop', static function (HttpServer|WebSocketServer $s, int $workerId) use ($config, $runtime): void {
            $system = $runtime->system;
            if ($system !== null) {
                try {
                    $system->shutdown($config->shutdownTimeout);
                } catch (Throwable $e) {
                    $config->logger->error('System shutdown failed in WorkerStop', ['exception' => $e, 'workerId' => $workerId]);
                }
            }
            $runtime->reset();
        });

        // Swoole SWOOLE_THREAD mode wires SIGTERM/SIGINT natively. installSignalHandlers retained
        // for API parity with worker mode — no-op here.

        $server->start();
    }

    private static function recordFailureAndMaybeShutdown(
        HttpServer|WebSocketServer $server,
        SwooleThreadConfig $config,
        ThreadServerRuntime $runtime,
    ): void {
        $now = microtime(true);
        $bucket = $runtime->failureBucket;
        if ($bucket['since'] === 0.0 || $now - $bucket['since'] > 5.0) {
            $bucket = ['count' => 1, 'since' => $now];
        } else {
            $bucket['count']++;
        }
        $runtime->failureBucket = $bucket;
        if ($bucket['count'] >= 3) {
            $config->logger->error('HTTP factory failed during thread boot 3 times in 5s — shutting down server.');
            $server->shutdown();
        }
    }
}
```

NOTE: this version drops the cross-thread frame-push plumbing (per-thread router actors, `routerSenders`, `computeRouterNames`). That plumbing existed only to enable a *future* cross-thread channel-mode broadcast — which the spec explicitly defers to v2. By rejecting channel routes at boot, `ThreadAwareConnectionContext` is effectively used for handler mode only, where the owning thread is always the calling thread. If a follow-up brings v2 cross-thread channel support, the router actors + `WebSocketFramePush` plumbing comes back inside `nexus-http-ws` (so the worker mode benefits too) or stays in this runner.

The `WebSocket/Message/WebSocketFramePush.php` file is kept in the package for now (deleted in Task 19 if confirmed unused).

- [ ] **Step 5: Lint**

```bash
docker exec -i nexus-php-1 vendor/bin/psalm packages/nexus-http-server-swoole-threads/src 2>&1 | tail -5
docker exec -i nexus-php-1 vendor/bin/phpcs packages/nexus-http-server-swoole-threads/src 2>&1 | tail -5
```

Expected: psalm + phpcs clean for new files. Old files (still present) may surface unused-class warnings — defer until Task 19.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http-server-swoole-threads/
git -c commit.gpgsign=false commit -m "feat(http-server-swoole-threads): rewrite thread runner around nexus-http-ws

Adds SwooleThreadServer + ThreadAwareConnectionContext. Slims
ThreadServerRuntime. Old SwooleThreadHttpServer kept temporarily."
```

---

## Task 16: Migrate worker-mode integration tests

The integration tests under `tests/Integration/HttpSwoole/` use the old DSL. Migrate them to the new `WsApplication`/`HttpApplication` + `SwooleWorkerServer` API.

**Files:**
- Modify: every `*.php` under `tests/Integration/HttpSwoole/` that currently references `SwooleHttpApp`, `SwooleWorkerHttpServer`, `webSocket(...)`, `webSocketChannel(...)`

- [ ] **Step 1: Enumerate the affected files**

```bash
docker exec -i nexus-php-1 grep -rl "SwooleHttpApp\|SwooleWorkerHttpServer\|webSocket(\|webSocketChannel(" tests/Integration/HttpSwoole/ 2>&1 | tail
```

Record the list; you'll touch each.

- [ ] **Step 2: For each integration test, apply the rename**

The mechanical changes per file:
- `use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;` → `use Monadial\Nexus\Http\Ws\WsApplication;`
- `use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerHttpServer;` → `use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;`
- `SwooleHttpApp::wrap($http, $system)` → `WsApplication::decorate($http, $system)` (where `$http` is a nexus-http `HttpApp`). For new code prefer `WsApplication::create($system)` (use this form when the test doesn't need a pre-configured `HttpApp`).
- `->webSocket($path, fn($ctx) => new EchoHandler($ctx))` → `->ws($path, EchoHandler::class)` (and update the handler class to extend `\Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler` with a `#[FromContext]` constructor param).
- `->webSocketChannel($path, $props, $keyFrom)` → `->channel($path, ChatRoomActor::class, key: $keyFrom)` (and update the actor class to extend `\Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor`).
- `SwooleWorkerHttpServer::run($config, $factory)` where `$factory` returns `CompiledHttpApp` → wrap with `new CompiledHttpApplication($factory($system))` OR if the factory builds a `SwooleHttpApp`, return `WsApplication::create($system)->...->compile()`.
- `->compile()` continues to work; result type changes from `SwooleCompiledHttpApp` to `CompiledHttpApplication`/`CompiledWsApplication`.

For test support classes under `tests/Integration/HttpSwoole/Support/` (e.g. `ChannelChatBehavior.php`, `thread_websocket_server_bootstrap.php`): rewrite handlers/actors to match the new bases (`WebSocketHandler` / `WebSocketChannelActor`).

- [ ] **Step 3: Verify**

```bash
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=integration-http-swoole 2>&1 | tail -10
```

Expected: same number of integration tests as before, all green.

- [ ] **Step 4: Lint**

```bash
docker exec -i nexus-php-1 vendor/bin/psalm tests/Integration/HttpSwoole 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs tests/Integration/HttpSwoole 2>&1 | tail -3
```

Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/HttpSwoole/
git -c commit.gpgsign=false commit -m "test(http-swoole): migrate worker-mode integration tests to new WsApplication DSL"
```

---

## Task 17: Migrate thread-mode integration tests

Same shape as Task 16, but the thread-mode tests under `tests/Integration/HttpSwoole/` (the ones using `SwooleThreadHttpServer`). NOTE: there is no separate `tests/Integration/HttpSwooleThreads/` directory — confirm with:

```bash
docker exec -i nexus-php-1 find tests/Integration -type d -name 'HttpSwoole*'
```

If the thread tests live alongside worker tests under `tests/Integration/HttpSwoole/`, the changes overlap with Task 16. In that case treat Tasks 16 and 17 as one logical migration but split here for review granularity.

- [ ] **Step 1: Enumerate thread-mode references**

```bash
docker exec -i nexus-php-1 grep -rl "SwooleThreadHttpServer\|thread_http_server_bootstrap\|thread_websocket_server_bootstrap" tests/ 2>&1 | tail
```

- [ ] **Step 2: Rename and rewrite**

Per-file changes:
- `SwooleThreadHttpServer::run` → `SwooleThreadServer::run`
- Bootstrap scripts (`thread_http_server_bootstrap.php`, `thread_websocket_server_bootstrap.php`): rewrite to build a `WsApplication`/`HttpApplication` and pass `->compile()` into `SwooleThreadServer::run`.
- Any thread-mode test that registered a CHANNEL route in the old API is now expected to assert that `SwooleThreadServer::run` throws `UnsupportedRouteException` at boot. Rewrite the assertion accordingly.

- [ ] **Step 3: Verify**

```bash
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=integration-http-swoole --filter='Thread' 2>&1 | tail -10
```

Expected: green.

- [ ] **Step 4: Commit**

```bash
git add tests/
git -c commit.gpgsign=false commit -m "test(http-swoole): migrate thread-mode integration tests to new API"
```

---

## Task 18: Migrate performance tests

Same kind of mechanical rename for `tests/Performance/HttpSwoole/` and `tests/Performance/HttpSwooleThreads/`.

- [ ] **Step 1: Enumerate**

```bash
docker exec -i nexus-php-1 grep -rl "SwooleHttpApp\|SwooleWorkerHttpServer\|SwooleThreadHttpServer\|webSocket(\|webSocketChannel(" tests/Performance/ 2>&1 | tail
```

- [ ] **Step 2: Apply renames per Task 16/17 substitution table**

- [ ] **Step 3: Run perf tests**

```bash
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=performance-http-swoole 2>&1 | tail -5
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=performance-http-swoole-threads 2>&1 | tail -5
```

Expected: all P99 budgets still met. If any perf test fails, investigate before continuing.

- [ ] **Step 4: Commit**

```bash
git add tests/Performance/
git -c commit.gpgsign=false commit -m "test(http-swoole): migrate performance tests to new API"
```

---

## Task 19: Delete obsolete files in old packages

Now that runners + tests use the new API, the duplicated code in the old packages is dead. Delete it.

**Files to delete:**

```
packages/nexus-http-server-swoole/src/App/SwooleHttpApp.php
packages/nexus-http-server-swoole/src/App/SwooleCompiledHttpApp.php
packages/nexus-http-server-swoole/src/Server/SwooleWorkerHttpServer.php
packages/nexus-http-server-swoole/src/WebSocket/ChannelActorNameResolver.php
packages/nexus-http-server-swoole/src/WebSocket/ChannelActorRegistry.php
packages/nexus-http-server-swoole/src/WebSocket/ConnectionTable.php
packages/nexus-http-server-swoole/src/WebSocket/LocalWebSocketContext.php
packages/nexus-http-server-swoole/src/WebSocket/Message/ChannelConnectionClosed.php
packages/nexus-http-server-swoole/src/WebSocket/Message/ChannelConnectionOpened.php
packages/nexus-http-server-swoole/src/WebSocket/Message/ChannelMessageReceived.php
packages/nexus-http-server-swoole/src/WebSocket/WebSocketContext.php
packages/nexus-http-server-swoole/src/WebSocket/WebSocketFrame.php
packages/nexus-http-server-swoole/src/WebSocket/WebSocketHandler.php
packages/nexus-http-server-swoole/src/WebSocket/WebSocketRegistry.php
packages/nexus-http-server-swoole/src/WebSocket/WebSocketRoute.php
packages/nexus-http-server-swoole/src/WebSocket/WebSocketRouter.php
packages/nexus-http-server-swoole-threads/src/Server/SwooleThreadHttpServer.php
packages/nexus-http-server-swoole-threads/src/WebSocket/ThreadAwareWebSocketContext.php
```

Plus old unit tests under those packages that test the deleted classes:

```
packages/nexus-http-server-swoole/tests/Unit/WebSocket/*
packages/nexus-http-server-swoole/tests/Unit/App/SwooleHttpAppTest.php
packages/nexus-http-server-swoole-threads/tests/Unit/Server/SwooleThreadHttpServerComputeRouterNamesTest.php
packages/nexus-http-server-swoole-threads/tests/Unit/Server/SwooleThreadHttpServerChannelRouteRejectionTest.php
packages/nexus-http-server-swoole-threads/tests/Unit/WebSocket/ThreadAwareWebSocketContextTest.php
```

`WebSocket/Message/WebSocketFramePush.php` in the threads package is kept ONLY if a v2 cross-thread channel mode is on the immediate roadmap. The new SwooleThreadServer does not use it; delete it too unless a follow-up issue tracks v2 work.

- [ ] **Step 1: List candidate deletions**

```bash
docker exec -i nexus-php-1 ls packages/nexus-http-server-swoole/src/App/ packages/nexus-http-server-swoole/src/WebSocket/ 2>&1
docker exec -i nexus-php-1 ls packages/nexus-http-server-swoole-threads/src/Server/ packages/nexus-http-server-swoole-threads/src/WebSocket/ 2>&1
```

- [ ] **Step 2: Delete the files listed above**

```bash
git rm packages/nexus-http-server-swoole/src/App/SwooleHttpApp.php
git rm packages/nexus-http-server-swoole/src/App/SwooleCompiledHttpApp.php
git rm packages/nexus-http-server-swoole/src/Server/SwooleWorkerHttpServer.php
git rm -r packages/nexus-http-server-swoole/src/WebSocket/
git rm packages/nexus-http-server-swoole-threads/src/Server/SwooleThreadHttpServer.php
git rm -r packages/nexus-http-server-swoole-threads/src/WebSocket/
git rm -r packages/nexus-http-server-swoole/tests/Unit/WebSocket/ 2>/dev/null || true
git rm packages/nexus-http-server-swoole/tests/Unit/App/SwooleHttpAppTest.php 2>/dev/null || true
git rm packages/nexus-http-server-swoole-threads/tests/Unit/Server/SwooleThreadHttpServerComputeRouterNamesTest.php 2>/dev/null || true
git rm packages/nexus-http-server-swoole-threads/tests/Unit/Server/SwooleThreadHttpServerChannelRouteRejectionTest.php 2>/dev/null || true
```

- [ ] **Step 3: Remove now-empty `App/` directory if it remains**

```bash
docker exec -i nexus-php-1 find packages/nexus-http-server-swoole/src/App -type d -empty -delete 2>&1
docker exec -i nexus-php-1 find packages/nexus-http-server-swoole-threads/src/WebSocket -type d -empty -delete 2>&1
```

- [ ] **Step 4: Full repo psalm + phpcs**

```bash
docker exec -i nexus-php-1 vendor/bin/psalm 2>&1 | tail -5
docker exec -i nexus-php-1 vendor/bin/phpcs 2>&1 | tail -5
```

Expected: clean. Any "class not found" errors from `use` statements pointing at deleted files mean a missed migration in Task 16/17 — fix the offending file and re-run.

- [ ] **Step 5: Full repo unit + integration tests**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=unit-swoole 2>&1 | tail -5
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=integration-http-swoole 2>&1 | tail -5
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=integration-worker-pool-swoole 2>&1 | tail -5
```

Expected: all green; total test counts may differ from pre-rework because old unit tests are gone but new `nexus-http-ws` ones are in.

- [ ] **Step 6: Commit**

```bash
git add -A
git -c commit.gpgsign=false commit -m "chore(http-swoole): delete obsolete WebSocket DSL files

All WebSocket routing, dispatcher, connection-table, channel-actor
registry, and the SwooleHttpApp wrapper now live in nexus-http-ws.
The runner packages keep only Swoole glue."
```

---

## Task 20: README updates

Three READMEs need rewrites: a new one for `nexus-http-ws`, and rewrites for the two runner packages.

**Files:**
- Create: `packages/nexus-http-ws/README.md`
- Modify: `packages/nexus-http-server-swoole/README.md`
- Modify: `packages/nexus-http-server-swoole-threads/README.md`

- [ ] **Step 1: Write nexus-http-ws README**

Write `packages/nexus-http-ws/README.md`:

````markdown
# nexus-http-ws

Runtime-agnostic WebSocket DSL for nexus-http. Defines `Application` / `WsApplication`, the `WebSocketHandler` / `WebSocketChannelActor` base classes, the dispatcher, the router, and PSR-11 + attribute-based handler injection. Used by both Swoole runner packages.

## Install

```bash
composer require nexus-actors/http-ws
```

## Quickstart

```php
use Monadial\Nexus\Http\Ws\Application;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;

final class EchoHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
    ) {}

    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }
}

$app = WsApplication::create($system);
$app->get('/api/users', UsersController::class);
$app->ws('/ws/echo', EchoHandler::class);

// Pass $app->compile() to a runner — SwooleWorkerServer or SwooleThreadServer.
```

## Handler vs channel mode

Two modes share one DSL:

- `ws(string $path, class-string<WebSocketHandler>)` — one POPO handler instance per connection. Stateless or per-connection state. Resolved via PSR-11; constructor params can use `#[FromContext]` for the connection and `#[FromActor('name')]` for any registered actor.
- `channel(string $path, class-string<WebSocketChannelActor>, string $key)` — one actor per path-param value. All connections to `/ws/room/lobby` share the lobby actor; `/ws/room/room42` gets its own. The base class translates internal channel messages into typed `onOpened`/`onMessage`/`onClosed` hooks and provides a `broadcast()` helper.

## Architecture

- `Application` interface — full HTTP surface (every method `HttpApp` exposes) plus `compile()`.
- `HttpApplication implements Application` — HTTP-only concrete, delegates to a wrapped `HttpApp`. Compiles to `CompiledHttpApplication`.
- `WsApplication implements Application` — decorates any `Application` and adds WebSocket methods. Compiles to `CompiledWsApplication`.
- `CompiledApplication` interface extends PSR-15 `RequestHandlerInterface` plus `hasWebSocketRoutes()`. Two impls (`CompiledHttpApplication`, `CompiledWsApplication`). Runners take the interface.
- `WebSocketDispatcher` is the runtime-side seam: `dispatchOpen`/`dispatchMessage`/`dispatchClose`. Runners call these; everything else (routing, handler resolution, channel actor management, connection-table maintenance) lives here.

## Status

Stable. Channel actors are local to a single runner process/thread; cross-process / cross-thread channel sharing is out of scope for v1.
````

- [ ] **Step 2: Rewrite worker package README**

Replace `packages/nexus-http-server-swoole/README.md` with a worker-mode-focused quickstart that demonstrates `SwooleWorkerServer::run($app->compile(), $config)` and links to `nexus-http-ws` for the DSL details. Keep the configuration table.

```markdown
# nexus-http-server-swoole

Worker-mode (Swoole process workers) HTTP+WebSocket runner. Implements nexus-http's `HttpServerAdapter` and ships `SwooleWorkerServer::run()` as the static entrypoint. The HTTP/WebSocket DSL lives in [nexus-http-ws](../nexus-http-ws); this package is just the Swoole glue.

## Install

```bash
composer require nexus-actors/http-server-swoole
```

## Quickstart

```php
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;

SwooleWorkerServer::run(
    SwooleWorkerConfig::bind('0.0.0.0', 8080)
        ->workers(4)
        ->enableWebSocket(true),
    static function ($system) {
        $app = WsApplication::create($system);
        $app->get('/health', static fn() => Response::ok('ok'));
        $app->ws('/ws/echo', EchoHandler::class);
        return $app->compile();
    },
);
```

Each worker process gets its own `ActorSystem` + `CompiledApplication`. WebSocket events (`Open/Message/Close`) are only wired when the compiled app reports `hasWebSocketRoutes() === true` AND `enableWebSocket(true)` is set on the config.

Channel actors are worker-local — each worker has its own. Connections to the same channel landing on different workers see different actors.

## Configuration

```php
SwooleWorkerConfig::bind('0.0.0.0', 8080)
    ->workers(8)
    ->reactorThreads(2)
    ->maxRequest(10_000)
    ->maxConn(100_000)
    ->dispatchMode(2)
    ->shutdownTimeout(Duration::seconds(10))
    ->enableWebSocket(true)
    ->installSignalHandlers(true)
    ->logger($psrLogger);
```

## Architecture

- One `CompiledApplication` per worker, built at `WorkerStart` via the user factory.
- Restart-loop protection: 3 factory failures in 5s → `Server::shutdown()`.
- See `nexus-http-ws` for the DSL, handler/channel actor bases, and WS routing.

## Status

Stable.
```

- [ ] **Step 3: Rewrite threads package README**

```markdown
# nexus-http-server-swoole-threads

Thread-mode (Swoole 6 SWOOLE_THREAD) HTTP+WebSocket runner. Same DSL as the worker package — see [nexus-http-ws](../nexus-http-ws). Uses Swoole's native thread mode for shared-memory pool-singleton actors.

Requires Swoole ≥ 6.0 with `--enable-swoole-thread` (ZTS PHP 8.5+).

## Install

```bash
composer require nexus-actors/http-server-swoole-threads
```

## HTTP quickstart

```php
SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)->threads(8),
    static function (ActorSystem $system, WorkerNode $node) {
        $app = WsApplication::create($system);
        $app->get('/api/users', UsersController::class);
        return $app->compile();
    },
);
```

## Pool-singleton actors

Across N HTTP-serving threads, declare an actor as `PoolSingleton` and the framework places it on whichever thread the hash ring assigns. All other threads' handlers reach it through a `WorkerActorRef`.

```php
$app->withPoolSingletonSpawner(new WorkerNodePoolSingletonSpawner($node));
$app->actor('store', $storeProps)->poolSingleton();
```

## WebSocket — handler mode only in v1

Enable via `->enableWebSocket(true)` on the config. **Channel-mode routes (`channel(...)`) are rejected at boot**: the channel-actor message payload is not serialization-safe across `Thread\Queue`, so accepting them under thread-distributed load would violate the per-channel-key actor guarantee. Use handler-mode WebSocket here, or switch to `nexus-actors/http-server-swoole` (worker mode) for channel actors.

## Configuration

```php
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(8)
    ->maxRequest(10_000)
    ->shutdownTimeout(Duration::seconds(10))
    ->enableWebSocket(true)
    ->logger($psrLogger);
```

## Status

Thread-mode HTTP + handler-mode WebSocket — stable. Channel-mode WebSocket — rejected at boot in v1 (see above).
```

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http-ws/README.md packages/nexus-http-server-swoole/README.md packages/nexus-http-server-swoole-threads/README.md
git -c commit.gpgsign=false commit -m "docs(http-swoole): rewrite READMEs for new Application DSL + three-package layout"
```

---

## Task 21: Final verification matrix + commit cleanup

- [ ] **Step 1: Full repo lint**

```bash
docker exec -i nexus-php-1 vendor/bin/psalm 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/phpcs 2>&1 | tail -3
docker exec -i nexus-php-1 vendor/bin/php-cs-fixer fix --dry-run 2>&1 | tail -5
docker exec -i nexus-php-1 php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse 2>&1 | tail -5
```

Expected: psalm clean (99%+ inferred); phpcs clean; cs-fixer 0 fixable; deptrac 0 violations.

- [ ] **Step 2: Full test matrix**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit --testsuite=unit 2>&1 | tail -3
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=unit-swoole 2>&1 | tail -3
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=integration-http-swoole 2>&1 | tail -3
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=integration-worker-pool-swoole 2>&1 | tail -3
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=performance-http-swoole 2>&1 | tail -3
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=performance-http-swoole-threads 2>&1 | tail -3
```

Expected: all green.

- [ ] **Step 3: Coverage check for nexus-http-ws**

```bash
docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-ws/tests/Unit --coverage-text --colors=never 2>&1 | tail -20
```

Expected: nexus-http-ws methods at ≥90% coverage.

- [ ] **Step 4: Final commit (if needed)**

If the previous steps required any fixes (typo in a use statement, a missed namespace, a stale import), commit:
```bash
git add -A
git -c commit.gpgsign=false commit -m "chore: final verification fixes"
```

- [ ] **Step 5: Summary**

Print a summary of the redesign delivery — count of new files, count of deleted files, net LOC delta — to share with the user.

```bash
git diff --stat $(git merge-base HEAD main)..HEAD 2>&1 | tail -10
```

---

## Self-review checklist

After each phase of execution, the subagent-driven-development reviewer compares against this checklist:

1. **Application interface** — every method `HttpApp` exposes (per `grep -nE "^\s*public function" packages/nexus-http/src/Dsl/HttpApp.php`) is on the interface? `HttpApplication` and `WsApplication` both implement them by delegation? Yes — Task 4–5, 12.
2. **CompiledApplication interface + two impls** — extends `RequestHandlerInterface`; `hasWebSocketRoutes()` is the discriminator; `CompiledWsApplication` additionally exposes `webSocketRouter()` + `dispatcher()`? Yes — Tasks 4, 5, 12.
3. **WebSocketHandler base** — abstract POPO; `onOpen`/`onMessage`/`onClose`; `#[FromContext]` injection? Yes — Tasks 6, 11.
4. **WebSocketChannelActor base** — extends `StatefulActorHandler`; typed `onOpened`/`onMessage`/`onClosed`; `broadcast()` + `connections()` helpers; `final handle()` translates system messages? Yes — Task 10.
5. **WebSocketDispatcher** — `dispatchOpen`/`dispatchMessage`/`dispatchClose`; PSR-11 resolution via `HandlerInstantiator`; channel actor resolution via `ChannelActorRegistry`; ConnectionTable maintenance? Yes — Task 11.
6. **Channel-route rejection at boot** — `WebSocketRouter::assertNoChannelRoutes()`; called by `SwooleThreadServer::run()` before `$server->start()`? Yes — Tasks 3, 15.
7. **Restart-loop protection** — present in BOTH `SwooleWorkerServer` and `SwooleThreadServer`? Yes — Tasks 14, 15.
8. **No WS event registration when no WS routes** — `enableWebSocket(false)` OR `hasWebSocketRoutes() === false` skips `Open/Message/Close` event wiring? Yes — Tasks 14, 15.
9. **PSR-11 + attribute injection** — `#[FromContext]` validates parameter type `WebSocketContext`; throws on mismatch? Yes — Task 11.
10. **Tests** — unit testsuite for `nexus-http-ws` covers `Application`, `WsApplication`, both compiled types, `WebSocketRouter`, `WebSocketDispatcher`, `WebSocketChannelActor`, `InMemoryConnectionTable`, `ChannelActorNameResolver`, `ChannelActorRegistry`, `HandlerInstantiator`? Yes — Tasks 5–12.
11. **Integration tests migrated** — handler-mode echo (both runners), channel-mode broadcast (worker only), thread-mode channel-route rejection? Yes — Tasks 16, 17.
12. **Performance tests migrated** — HTTP throughput + WS broadcast/echo, both runners? Yes — Task 18.
13. **Deletions** — `SwooleHttpApp`, `SwooleCompiledHttpApp`, `SwooleWorkerHttpServer`, `SwooleThreadHttpServer`, old `WebSocket/*` trees? Yes — Task 19.
14. **READMEs** — new `nexus-http-ws` README; rewritten runner READMEs? Yes — Task 20.

