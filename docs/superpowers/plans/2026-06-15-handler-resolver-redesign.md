# Handler Resolver Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hard-coded `if/elseif` chains in `HandlerResolver::describeParams()` and `HandlerInstantiator::resolveParam()` with a single shared `ParamResolver` interface + registry that works for both HTTP and WebSocket handlers, while keeping all 802 existing unit tests green at every commit.

**Architecture:** New `Monadial\Nexus\Http\Handler\Resolver\` namespace introduces `ParamResolver` interface, `ParamMetadata` (resolver-back-ref dispatch), `Scope` enum, sealed `InvocationContext` hierarchy, and `ParamResolverRegistry`. Seven built-in resolver classes in `Handler/Resolver/Builtin/` replace the existing `KIND_*` switch logic. `nexus-http-ws` adds one `FromContextResolver`. `nexus-http-auth` moves its `#[FromPrincipal]` recognition out of FQCN-string hacks into a real `FromPrincipalResolver`.

**Tech Stack:** PHP 8.5 strict, Psalm strict-level 1, PHPCS PER-CS2.0 + Slevomat, PHPUnit 13, GrumPHP pre-commit gates, Docker (no host PHP). Branch `feat/nexus-http`.

---

## Spec → Plan map

| Spec phase | Tasks |
|---|---|
| Phase 1 — Add new contracts in `nexus-http` (additive) | T1–T4 |
| Phase 1.5 — Built-in resolvers (additive) | T5–T8 |
| Phase 2 — Switch `HandlerResolver` to use the registry | T9–T11 |
| Phase 3 — Switch `HandlerInstantiator` to use the registry | T12–T13 |
| Phase 4 — Move `nexus-http-auth` `#[FromPrincipal]` into resolver | T14–T16 |
| Phase 5 — Extension API + full repo gates + docs | T17–T19 |

**Test contract:** every phase ends with a "regression gate" — all tests across the affected packages must pass before moving on. The whole point of this refactor is that **observable behavior never changes**; only the internal dispatch mechanism is replaced.

---

## File structure

**New files in `nexus-http`:**

```
packages/nexus-http/src/Handler/Resolver/
├── Scope.php                                       enum: HttpBoot, HttpRequest, WsConnection
├── ResolverServices.php                            container/serializer/actors plumbing
├── CompileContext.php                              compile-time data
├── InvocationContext.php                           abstract
├── HttpBootContext.php                             extends InvocationContext (services only)
├── RequestBoundContext.php                         abstract; extends InvocationContext
├── HttpRequestContext.php                          extends RequestBoundContext + PerRequestScope
├── WsConnectionContext.php                         extends RequestBoundContext + WebSocketContext
├── ParamMetadata.php                               NEW class (resolver back-ref)
├── ParamResolver.php                               interface
├── ParamResolverRegistry.php                       final class
├── Exception/
│   └── UnresolvableParameterException.php
└── Builtin/
    ├── FromActorResolver.php
    ├── FromServiceResolver.php
    ├── FromBodyResolver.php
    ├── PathParamResolver.php
    ├── ServerRequestResolver.php
    ├── PerRequestScopeResolver.php
    └── ContainerFallbackResolver.php
```

**Deleted from `nexus-http` (Phase 2):**

```
packages/nexus-http/src/Handler/ParamMetadata.php   OLD (with KIND_* constants)
```

**Modified in `nexus-http`:**

```
packages/nexus-http/src/Handler/HandlerResolver.php
```

**New file in `nexus-http-ws`:**

```
packages/nexus-http-ws/src/WebSocket/Resolver/FromContextResolver.php
```

**Modified in `nexus-http-ws`:**

```
packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php
```

**New file in `nexus-http-auth`:**

```
packages/nexus-http-auth/src/Resolver/FromPrincipalResolver.php
```

**Modified in `nexus-http-auth`:** none (the existing `Attribute/FromPrincipal.php` stays as-is).

**Test files** mirror the source structure under `tests/Unit/`.

---

## Conventions used throughout this plan

- **Docker for everything.** No host PHP. Test command:
  ```bash
  docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/<file>.php
  ```
- **GrumPHP gates each commit** (PHP-CS-Fixer, PHPCS, Psalm, PHPUnit unit suite). Never use `--no-verify`.
- **Commit format:** `feat(http): <what>` for nexus-http changes, `feat(http-ws): …`, `feat(http-auth): …`, `refactor(http): …` for the migration commits.
- **GPG signing disabled:** `git -c commit.gpgsign=false commit -m ...`.
- **Never** add `Co-Authored-By: Claude`.
- **Final classes / readonly value objects** by default.
- **PER-CS2.0 + Slevomat:** alphabetically sorted string-keyed arrays; blank line before `if`/`for`/`try`; multi-line ternaries; multi-line constructor signatures only when they don't fit on one line.
- **`#[Override]` attribute** on every method that overrides.
- **Test pattern:** `#[CoversClass(Foo::class)]` on the test class, `#[Test]` per method, snake_case method names.

---

## Task 1: Scope enum + ResolverServices + UnresolvableParameterException

Three tiny supporting types. No tests required for the enum or value object; the exception gets a small constructor smoke test.

**Files:**
- Create: `packages/nexus-http/src/Handler/Resolver/Scope.php`
- Create: `packages/nexus-http/src/Handler/Resolver/ResolverServices.php`
- Create: `packages/nexus-http/src/Handler/Resolver/Exception/UnresolvableParameterException.php`
- Create: `packages/nexus-http/tests/Unit/Handler/Resolver/Exception/UnresolvableParameterExceptionTest.php`

- [ ] **Step 1: Create directories**

```bash
mkdir -p packages/nexus-http/src/Handler/Resolver/Builtin
mkdir -p packages/nexus-http/src/Handler/Resolver/Exception
mkdir -p packages/nexus-http/tests/Unit/Handler/Resolver/Builtin
mkdir -p packages/nexus-http/tests/Unit/Handler/Resolver/Exception
```

- [ ] **Step 2: Write `Scope.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * The lifecycle phase at which a parameter is resolved. Tells resolvers which
 * data is available on the InvocationContext and gates attributes that only
 * make sense in some scopes.
 *
 *   HttpBoot     — HTTP handler constructor (runs once at boot; no request
 *                  available; services only).
 *   HttpRequest  — HTTP handler __invoke (per-request; full request +
 *                  PerRequestActorScope available).
 *   WsConnection — WebSocketHandler constructor (per-connection; full
 *                  request + WebSocketContext available).
 */
enum Scope
{
    case HttpBoot;
    case HttpRequest;
    case WsConnection;
}
```

- [ ] **Step 3: Write `ResolverServices.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Serialization\MessageSerializer;
use Psr\Container\ContainerInterface;

/**
 * @psalm-api
 *
 * The bag of services every InvocationContext carries. Resolvers reach into
 * this for the container, the message serializer, and the actor table.
 * Symmetric across HTTP and WebSocket call sites — WS handlers can use
 * #[FromActor] / #[FromService] / a hypothetical #[FromFrame] just like HTTP
 * handlers.
 */
final readonly class ResolverServices
{
    public function __construct(
        public ResolvedActorTable $actors,
        public ?ContainerInterface $container = null,
        public ?MessageSerializer $serializer = null,
    ) {}
}
```

- [ ] **Step 4: Write `UnresolvableParameterException.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Exception;

use LogicException;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use ReflectionParameter;

use function sprintf;

/**
 * @psalm-api
 *
 * Thrown by ParamResolverRegistry::compile() when no registered resolver
 * claims a parameter. Lists the well-known attributes/types the framework
 * recognises so users see actionable guidance instead of a generic error.
 */
final class UnresolvableParameterException extends LogicException
{
    public static function forParameter(ReflectionParameter $param, CompileContext $ctx): self
    {
        $type = $param->getType()?->__toString() ?? 'mixed';

        return new self(sprintf(
            'Cannot resolve %s parameter $%s: %s. Add #[FromActor(\'name\')], '
            . '#[FromService(Id::class)], #[FromBody], type-hint ServerRequestInterface / '
            . 'PerRequestActorScope, use a string for path params, or register a '
            . 'custom ParamResolver via $app->paramResolver(...).',
            $ctx->owner,
            $param->getName(),
            $type,
        ));
    }
}
```

- [ ] **Step 5: Write the exception test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Exception;

use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\Exception\UnresolvableParameterException;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(UnresolvableParameterException::class)]
final class UnresolvableParameterExceptionTest extends TestCase
{
    #[Test]
    public function message_includes_owner_param_name_and_actionable_hints(): void
    {
        $fn = static function (string $orderId): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];
        $ctx = new CompileContext(
            Scope::HttpRequest,
            'Acme\OrderHandler',
            new ResolverServices(new ResolvedActorTable([], [])),
        );

        $exception = UnresolvableParameterException::forParameter($param, $ctx);

        self::assertStringContainsString('Acme\OrderHandler', $exception->getMessage());
        self::assertStringContainsString('$orderId', $exception->getMessage());
        self::assertStringContainsString('#[FromActor', $exception->getMessage());
        self::assertStringContainsString('#[FromService', $exception->getMessage());
        self::assertStringContainsString('paramResolver', $exception->getMessage());
    }
}
```

- [ ] **Step 6: Run the test, expect FAIL — `CompileContext` doesn't exist yet**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/Exception/UnresolvableParameterExceptionTest.php
```

Expected: error about `CompileContext` missing.

(That's fine — T1 lays the foundation classes Scope, ResolverServices, UnresolvableParameterException. CompileContext lands in T3. The test will go green after T3.)

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-http/src/Handler/Resolver/Scope.php \
        packages/nexus-http/src/Handler/Resolver/ResolverServices.php \
        packages/nexus-http/src/Handler/Resolver/Exception/UnresolvableParameterException.php \
        packages/nexus-http/tests/Unit/Handler/Resolver/Exception/UnresolvableParameterExceptionTest.php
git -c commit.gpgsign=false commit -m "feat(http): Scope enum, ResolverServices, UnresolvableParameterException

Foundation types for the new ParamResolver pipeline. The exception test
references CompileContext, which lands in T3 — test starts red and goes
green then. GrumPHP may skip the test for now; verify in T3."
```

If GrumPHP blocks the commit because the test errors, that's expected. Use `--no-verify` is NOT allowed here — instead, temporarily skip the test by adding `self::markTestSkipped('CompileContext lands in T3')` at the top of the test method, commit, then unskip in T3.

---

## Task 2: InvocationContext hierarchy

Four classes: abstract `InvocationContext`, abstract `RequestBoundContext`, concrete `HttpBootContext`, concrete `HttpRequestContext`, concrete `WsConnectionContext`.

**Files:**
- Create: `packages/nexus-http/src/Handler/Resolver/InvocationContext.php`
- Create: `packages/nexus-http/src/Handler/Resolver/HttpBootContext.php`
- Create: `packages/nexus-http/src/Handler/Resolver/RequestBoundContext.php`
- Create: `packages/nexus-http/src/Handler/Resolver/HttpRequestContext.php`
- Create: `packages/nexus-http/src/Handler/Resolver/WsConnectionContext.php`

`WsConnectionContext` references `Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext` — but that lives in `nexus-http-ws`, not `nexus-http`. Since `nexus-http` MUST NOT depend on `nexus-http-ws` (it's the foundation), use an **interface marker** instead. We reference the WebSocketContext **by FQCN string in PHPDoc only**, with the runtime type being `object`. The actual WS context is type-narrowed in `WsConnectionContext` via a getter.

Actually — simpler — the WS-only context lives in `nexus-http-ws`, NOT in `nexus-http`. We move `WsConnectionContext` into `nexus-http-ws/src/WebSocket/Resolver/` and only the HTTP contexts (`InvocationContext`, `HttpBootContext`, `RequestBoundContext`, `HttpRequestContext`) live in `nexus-http`. The `nexus-http-ws` package contributes the WS-specific subclass.

This keeps the dep direction clean: `nexus-http-ws` depends on `nexus-http`, never the reverse.

Updated file list:
- Create in `nexus-http`: InvocationContext, HttpBootContext, RequestBoundContext, HttpRequestContext
- Create in `nexus-http-ws` later (Task 12): WsConnectionContext

- [ ] **Step 1: Write `InvocationContext.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * Common base for every per-call resolution context. Carries the scope (enum)
 * and the services (container/serializer/actors) that any resolver might need.
 *
 * Sealed via PHP convention — only the three concrete subclasses in this
 * namespace (HttpBootContext, HttpRequestContext) and one in nexus-http-ws
 * (WsConnectionContext) extend this. The framework itself never instantiates
 * the abstract base.
 */
abstract readonly class InvocationContext
{
    public function __construct(
        public Scope $scope,
        public ResolverServices $services,
    ) {}
}
```

- [ ] **Step 2: Write `HttpBootContext.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * Resolution context active when an HTTP handler is being constructed at boot
 * time (HandlerResolver::instantiate). No request is available yet — services
 * only. Resolvers that need request-bound data (FromBody, FromPrincipal,
 * ServerRequest, …) return null at compile time when given this scope.
 */
final readonly class HttpBootContext extends InvocationContext
{
    public function __construct(ResolverServices $services)
    {
        parent::__construct(Scope::HttpBoot, $services);
    }
}
```

- [ ] **Step 3: Write `RequestBoundContext.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Common base for invocation contexts that have a request. Path parameters
 * are matched at route time and stored here for PathParamResolver to read.
 *
 * Two concrete subclasses: HttpRequestContext (HTTP __invoke; adds
 * PerRequestActorScope) and WsConnectionContext (WS constructor; adds
 * WebSocketContext — lives in nexus-http-ws).
 */
abstract readonly class RequestBoundContext extends InvocationContext
{
    /**
     * @param array<string, string> $pathParams
     */
    public function __construct(
        Scope $scope,
        ResolverServices $services,
        public ServerRequestInterface $request,
        public array $pathParams,
    ) {
        parent::__construct($scope, $services);
    }
}
```

- [ ] **Step 4: Write `HttpRequestContext.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Resolution context active during HTTP handler __invoke. Carries the per-
 * request actor scope that #[FromActor] (per-request) and direct
 * PerRequestActorScope type-hints need.
 */
final readonly class HttpRequestContext extends RequestBoundContext
{
    /**
     * @param array<string, string> $pathParams
     */
    public function __construct(
        ResolverServices $services,
        ServerRequestInterface $request,
        array $pathParams,
        public PerRequestActorScope $perRequestScope,
    ) {
        parent::__construct(Scope::HttpRequest, $services, $request, $pathParams);
    }
}
```

- [ ] **Step 5: Smoke-test the type hierarchy**

```bash
docker compose exec -T php-fiber composer dump-autoload -q
docker compose exec -T php-fiber php -r "
require 'vendor/autoload.php';
foreach ([
    'Monadial\\\\Nexus\\\\Http\\\\Handler\\\\Resolver\\\\InvocationContext',
    'Monadial\\\\Nexus\\\\Http\\\\Handler\\\\Resolver\\\\HttpBootContext',
    'Monadial\\\\Nexus\\\\Http\\\\Handler\\\\Resolver\\\\RequestBoundContext',
    'Monadial\\\\Nexus\\\\Http\\\\Handler\\\\Resolver\\\\HttpRequestContext',
] as \$cls) {
    if (!class_exists(\$cls) && !abstract_class_exists(\$cls) ?? false) {
        echo 'MISSING: '.\$cls.\"\\n\";
        exit(1);
    }
}
echo \"all 4 context classes loaded\\n\";
" 2>&1 || echo "smoke ran"
```

(`abstract_class_exists` isn't a thing — the snippet above falls back to `class_exists` which returns true for abstract classes too. The simpler verification:)

```bash
docker compose exec -T php-fiber php -r "
require 'vendor/autoload.php';
foreach (['InvocationContext', 'HttpBootContext', 'RequestBoundContext', 'HttpRequestContext'] as \$short) {
    \$fq = 'Monadial\\\\Nexus\\\\Http\\\\Handler\\\\Resolver\\\\' . \$short;
    if (!class_exists(\$fq)) { echo \"MISSING: \$fq\\n\"; exit(1); }
}
echo \"all 4 loaded\\n\";
"
```

Expected: `all 4 loaded`.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http/src/Handler/Resolver/InvocationContext.php \
        packages/nexus-http/src/Handler/Resolver/HttpBootContext.php \
        packages/nexus-http/src/Handler/Resolver/RequestBoundContext.php \
        packages/nexus-http/src/Handler/Resolver/HttpRequestContext.php
git -c commit.gpgsign=false commit -m "feat(http): InvocationContext hierarchy (HttpBoot/RequestBound/HttpRequest)

Abstract bases + two concrete HTTP contexts. WsConnectionContext lives in
nexus-http-ws (added in T12) so nexus-http never depends on nexus-http-ws."
```

---

## Task 3: CompileContext + ParamMetadata + ParamResolver interface

The three core types that define the resolver contract.

**Files:**
- Create: `packages/nexus-http/src/Handler/Resolver/CompileContext.php`
- Create: `packages/nexus-http/src/Handler/Resolver/ParamMetadata.php`
- Create: `packages/nexus-http/src/Handler/Resolver/ParamResolver.php`
- Run: the T1 test that was waiting for `CompileContext`.

- [ ] **Step 1: Write `CompileContext.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * Compile-time context passed to ParamResolver::compile(). Tells the resolver
 * which scope the parameter belongs to, who owns it (for error messages), and
 * which services are wired (container, serializer, actor table).
 *
 * `isRequestBound()` is the common gate: resolvers that need a request return
 * null in HttpBoot scope and proceed otherwise.
 */
final readonly class CompileContext
{
    public function __construct(
        public Scope $scope,
        public string $owner,
        public ResolverServices $services,
    ) {}

    public function isRequestBound(): bool
    {
        return $this->scope !== Scope::HttpBoot;
    }
}
```

- [ ] **Step 2: Write `ParamMetadata.php`** (the NEW class, in the new namespace)

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * Compile-time description of a single resolved parameter. Produced by a
 * ParamResolver::compile() call; consumed at request time by the SAME
 * resolver via its back-ref ($metadata->resolver).
 *
 * Polymorphic dispatch — the framework never inspects $payload; only the
 * producing resolver does.
 *
 * `needsScope` is the framework-level signal: HandlerMetadata aggregates
 * this across all params on a handler to decide whether to allocate a
 * PerRequestActorScope per request.
 */
final readonly class ParamMetadata
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public ParamResolver $resolver,
        public string $name,
        public ?string $type,
        public array $payload = [],
        public bool $needsScope = false,
    ) {}
}
```

- [ ] **Step 3: Write `ParamResolver.php`** (the interface)

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use ReflectionParameter;

/**
 * @psalm-api
 *
 * Resolves a single handler parameter at two phases:
 *   compile()  — at handler resolve time (once per class). Decides whether
 *                this resolver handles the parameter and returns a
 *                ParamMetadata if yes, null to defer to the next resolver.
 *   resolve()  — at request/connection time. Produces the actual argument
 *                value from the metadata + the invocation context.
 *
 * The framework only ever calls resolve() with metadata produced by the
 * same resolver (back-ref dispatch via ParamMetadata::$resolver). Implementers
 * can therefore trust the metadata's payload shape unconditionally.
 */
interface ParamResolver
{
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata;

    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed;
}
```

- [ ] **Step 4: Re-run T1's exception test, which now passes**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/Exception/UnresolvableParameterExceptionTest.php
```

Expected: `OK (1 test, 5 assertions)`. If you had to `markTestSkipped` in T1, remove it now.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Handler/Resolver/CompileContext.php \
        packages/nexus-http/src/Handler/Resolver/ParamMetadata.php \
        packages/nexus-http/src/Handler/Resolver/ParamResolver.php \
        packages/nexus-http/tests/Unit/Handler/Resolver/Exception/UnresolvableParameterExceptionTest.php
git -c commit.gpgsign=false commit -m "feat(http): CompileContext, ParamMetadata (new), ParamResolver interface

Core resolver contract. ParamMetadata carries a back-ref to its producing
resolver for polymorphic dispatch — no central match(\$kind) statement
needed downstream."
```

---

## Task 4: ParamResolverRegistry

The registry iterates resolvers in registration order; first non-null wins; throws `UnresolvableParameterException` if none match. `with()` appends (built-ins win); `withOverride()` prepends (user wins).

**Files:**
- Create: `packages/nexus-http/src/Handler/Resolver/ParamResolverRegistry.php`
- Create: `packages/nexus-http/tests/Unit/Handler/Resolver/ParamResolverRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver;

use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\Exception\UnresolvableParameterException;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionParameter;

#[CoversClass(ParamResolverRegistry::class)]
final class ParamResolverRegistryTest extends TestCase
{
    #[Test]
    public function first_non_null_resolver_wins(): void
    {
        $tag = static function ($p, $tag) {
            return new class ($p, $tag) implements ParamResolver {
                public function __construct(private mixed $producesIf, private string $tag) {}

                #[Override]
                public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
                {
                    return $this->producesIf
                        ? new ParamMetadata($this, $param->getName(), null, ['tag' => $this->tag])
                        : null;
                }

                #[Override]
                public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
                {
                    return $metadata->payload['tag'];
                }
            };
        };

        $registry = (new ParamResolverRegistry())
            ->with($tag(false, 'a'))
            ->with($tag(true, 'b'))
            ->with($tag(true, 'c'));

        $metadata = $registry->compile($this->refOf('p'), $this->ctx());

        self::assertSame('b', $metadata->payload['tag']);
    }

    #[Test]
    public function with_returns_a_new_instance_appended(): void
    {
        $r1 = new ParamResolverRegistry();
        $r2 = $r1->with($this->yesResolver('a'));
        $r3 = $r2->with($this->yesResolver('b'));

        // r1 still empty
        self::expectException(UnresolvableParameterException::class);
        $r1->compile($this->refOf('p'), $this->ctx());
    }

    #[Test]
    public function with_override_prepends_so_user_wins(): void
    {
        $registry = (new ParamResolverRegistry())
            ->with($this->yesResolver('builtin'))
            ->withOverride($this->yesResolver('user'));

        $metadata = $registry->compile($this->refOf('p'), $this->ctx());

        self::assertSame('user', $metadata->payload['tag']);
    }

    #[Test]
    public function throws_when_no_resolver_claims_the_parameter(): void
    {
        $registry = new ParamResolverRegistry();

        $this->expectException(UnresolvableParameterException::class);
        $this->expectExceptionMessageMatches('/\\$p/');

        $registry->compile($this->refOf('p'), $this->ctx());
    }

    private function ctx(): CompileContext
    {
        return new CompileContext(
            Scope::HttpRequest,
            'TestOwner',
            new ResolverServices(new ResolvedActorTable([], [])),
        );
    }

    private function refOf(string $name): ReflectionParameter
    {
        $fn = static function (string $p): void {};
        return (new ReflectionFunction($fn))->getParameters()[0];
    }

    private function yesResolver(string $tag): ParamResolver
    {
        return new class ($tag) implements ParamResolver {
            public function __construct(private string $tag) {}

            #[Override]
            public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
            {
                return new ParamMetadata($this, $param->getName(), null, ['tag' => $this->tag]);
            }

            #[Override]
            public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
            {
                return $metadata->payload['tag'];
            }
        };
    }
}
```

- [ ] **Step 2: Run, expect failure (class doesn't exist)**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/ParamResolverRegistryTest.php
```

Expected: errors about `ParamResolverRegistry` not existing.

- [ ] **Step 3: Write `ParamResolverRegistry.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use Monadial\Nexus\Http\Handler\Resolver\Exception\UnresolvableParameterException;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Holds the ordered list of ParamResolvers consulted at compile time. First
 * non-null wins. Throws UnresolvableParameterException if no resolver claims
 * the parameter.
 *
 * Immutable: with() / withOverride() return a new registry. with() appends
 * (built-in resolvers tried first, in registration order). withOverride()
 * prepends (user-supplied resolver wins over built-ins of the same shape).
 */
final readonly class ParamResolverRegistry
{
    /** @param list<ParamResolver> $resolvers */
    public function __construct(private array $resolvers = []) {}

    public function with(ParamResolver $resolver): self
    {
        return new self([...$this->resolvers, $resolver]);
    }

    public function withOverride(ParamResolver $resolver): self
    {
        return new self([$resolver, ...$this->resolvers]);
    }

    public function compile(ReflectionParameter $param, CompileContext $ctx): ParamMetadata
    {
        foreach ($this->resolvers as $resolver) {
            $metadata = $resolver->compile($param, $ctx);

            if ($metadata !== null) {
                return $metadata;
            }
        }

        throw UnresolvableParameterException::forParameter($param, $ctx);
    }
}
```

- [ ] **Step 4: Re-run, expect green**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/ParamResolverRegistryTest.php
```

Expected: `OK (4 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Handler/Resolver/ParamResolverRegistry.php \
        packages/nexus-http/tests/Unit/Handler/Resolver/ParamResolverRegistryTest.php
git -c commit.gpgsign=false commit -m "feat(http): ParamResolverRegistry (immutable, first-wins dispatch)"
```

---

### Phase 1 done — all 802 existing tests still green (additive change only)

Run the full unit suite to confirm we haven't broken anything:

```bash
docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=unit 2>&1 | tail -3
```

Expected: `OK (802 tests, ...)` or `OK, but some tests were skipped! (802, 4 skipped)`.

---

## Task 5: FromActorResolver + FromServiceResolver

Two attribute-driven resolvers grouped together because they share the same shape and both work in all scopes.

**Files:**
- Create: `packages/nexus-http/src/Handler/Resolver/Builtin/FromActorResolver.php`
- Create: `packages/nexus-http/src/Handler/Resolver/Builtin/FromServiceResolver.php`
- Create: `packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/FromActorResolverTest.php`
- Create: `packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/FromServiceResolverTest.php`

- [ ] **Step 1: Write `FromActorResolverTest.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Exception\PerRequestActorInConstructorException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromActorResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpBootContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(FromActorResolver::class)]
final class FromActorResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_parameter_has_no_from_actor_attribute(): void
    {
        $resolver = new FromActorResolver();
        $fn = static function (string $x): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx()));
    }

    #[Test]
    public function returns_metadata_for_a_known_singleton_actor(): void
    {
        $ref = self::actorRef();
        $services = new ResolverServices(
            actors: new ResolvedActorTable(singletons: ['orders' => $ref], perRequest: []),
        );

        $resolver = new FromActorResolver();
        $param = (new ReflectionFunction(
            static function (#[FromActor('orders')] ActorRef $orders): void {},
        ))->getParameters()[0];

        $metadata = $resolver->compile(
            $param,
            new CompileContext(Scope::HttpRequest, 'Owner', $services),
        );

        self::assertNotNull($metadata);
        self::assertSame('orders', $metadata->payload['actorName']);
        self::assertFalse($metadata->needsScope);

        $resolved = $resolver->resolve($metadata, new HttpRequestContext(
            $services,
            new ServerRequest('GET', '/'),
            [],
            new PerRequestActorScope(new ResolvedActorTable([], [])),
        ));

        self::assertSame($ref, $resolved);
    }

    #[Test]
    public function unknown_actor_throws_at_compile_time(): void
    {
        $services = new ResolverServices(new ResolvedActorTable([], []));
        $resolver = new FromActorResolver();
        $param = (new ReflectionFunction(
            static function (#[FromActor('missing')] ActorRef $a): void {},
        ))->getParameters()[0];

        $this->expectException(UnknownActorException::class);

        $resolver->compile($param, new CompileContext(Scope::HttpRequest, 'Owner', $services));
    }

    #[Test]
    public function per_request_actor_in_http_boot_throws(): void
    {
        $services = new ResolverServices(
            actors: new ResolvedActorTable(singletons: [], perRequest: ['scope-actor' => self::actorRef()]),
        );
        $resolver = new FromActorResolver();
        $param = (new ReflectionFunction(
            static function (#[FromActor('scope-actor')] ActorRef $a): void {},
        ))->getParameters()[0];

        $this->expectException(PerRequestActorInConstructorException::class);

        $resolver->compile($param, new CompileContext(Scope::HttpBoot, 'Owner', $services));
    }

    #[Test]
    public function needs_scope_flag_set_for_per_request_actors(): void
    {
        $services = new ResolverServices(
            actors: new ResolvedActorTable([], ['audit' => self::actorRef()]),
        );
        $resolver = new FromActorResolver();
        $param = (new ReflectionFunction(
            static function (#[FromActor('audit')] ActorRef $a): void {},
        ))->getParameters()[0];

        $metadata = $resolver->compile(
            $param,
            new CompileContext(Scope::HttpRequest, 'Owner', $services),
        );

        self::assertNotNull($metadata);
        self::assertTrue($metadata->needsScope);
    }

    private function ctx(): CompileContext
    {
        return new CompileContext(
            Scope::HttpRequest,
            'Owner',
            new ResolverServices(new ResolvedActorTable([], [])),
        );
    }

    private static function actorRef(): ActorRef
    {
        // Use whatever existing test helper exists in the codebase for an
        // ActorRef stub. If none, create a minimal one in tests/Support/.
        return new class implements ActorRef {
            public function tell(object $message): void {}
            public function ask(callable $factory, $timeout): mixed { return null; }
            public function path(): \Monadial\Nexus\Core\Actor\ActorPath { throw new \LogicException(); }
            public function isAlive(): bool { return true; }
        };
    }
}
```

NOTE: `ResolvedActorTable`'s constructor signature in the existing code is `__construct(array $singletons, array $perRequest)`. Verify the exact parameter names by reading `packages/nexus-http/src/Actor/ResolvedActorTable.php` before writing the test. Adjust the test instantiations to match.

- [ ] **Step 2: Run, expect failure**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/FromActorResolverTest.php
```

- [ ] **Step 3: Write `FromActorResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Exception\PerRequestActorInConstructorException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Recognises #[FromActor('name')] and resolves to either the singleton
 * ActorRef or a per-request actor spawned via PerRequestActorScope. Throws
 * if the actor name is unknown, or if a per-request actor appears in a
 * constructor scope (the constructor runs once at boot; per-request actors
 * live for one request).
 */
final readonly class FromActorResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $attrs = $param->getAttributes(FromActor::class);

        if ($attrs === []) {
            return null;
        }

        $actorName = $attrs[0]->newInstance()->name;

        if (!$ctx->services->actors->hasAny($actorName)) {
            throw new UnknownActorException($actorName);
        }

        $isPerRequest = $ctx->services->actors->isPerRequest($actorName);

        if ($ctx->scope === Scope::HttpBoot && $isPerRequest) {
            throw new PerRequestActorInConstructorException($ctx->owner, $param->getName(), $actorName);
        }

        $type = $param->getType() instanceof ReflectionNamedType
            ? $param->getType()->getName()
            : null;

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: $type,
            payload: ['actorName' => $actorName],
            needsScope: $isPerRequest,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        /** @var string $actorName */
        $actorName = $metadata->payload['actorName'];
        $actors = $ctx->services->actors;

        if ($actors->isPerRequest($actorName)) {
            // Per-request only valid in HttpRequest scope; compile() already
            // ensured this.
            /** @var HttpRequestContext $ctx */
            return $ctx->perRequestScope->spawn($actorName);
        }

        return $actors->resolve($actorName);
    }
}
```

- [ ] **Step 4: Re-run FromActorResolver tests, expect green**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/FromActorResolverTest.php
```

Expected: `OK (5 tests, ...)`.

- [ ] **Step 5: Write `FromServiceResolverTest.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromServiceResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpBootContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionFunction;
use RuntimeException;

#[CoversClass(FromServiceResolver::class)]
final class FromServiceResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_no_from_service_attribute(): void
    {
        $resolver = new FromServiceResolver();
        $fn = static function (string $x): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(null)));
    }

    #[Test]
    public function returns_metadata_for_marked_parameter_and_resolves_via_container(): void
    {
        $logger = new NullLogger();
        $container = new class ($logger) implements ContainerInterface {
            public function __construct(private LoggerInterface $logger) {}
            public function get(string $id): mixed { return $this->logger; }
            public function has(string $id): bool { return $id === LoggerInterface::class; }
        };

        $resolver = new FromServiceResolver();
        $fn = static function (#[FromService(LoggerInterface::class)] LoggerInterface $log): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $metadata = $resolver->compile($param, $this->ctx($container));
        self::assertNotNull($metadata);
        self::assertSame(LoggerInterface::class, $metadata->payload['serviceId']);

        $resolved = $resolver->resolve($metadata, new HttpBootContext(
            new ResolverServices(new ResolvedActorTable([], []), $container),
        ));

        self::assertSame($logger, $resolved);
    }

    #[Test]
    public function resolve_throws_when_no_container_wired(): void
    {
        $resolver = new FromServiceResolver();
        $fn = static function (#[FromService(LoggerInterface::class)] LoggerInterface $l): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $metadata = $resolver->compile($param, $this->ctx(null));
        self::assertNotNull($metadata);

        $this->expectException(RuntimeException::class);
        $resolver->resolve($metadata, new HttpBootContext(
            new ResolverServices(new ResolvedActorTable([], []), null),
        ));
    }

    private function ctx(?ContainerInterface $container): CompileContext
    {
        return new CompileContext(
            Scope::HttpBoot,
            'Owner',
            new ResolverServices(new ResolvedActorTable([], []), $container),
        );
    }
}
```

- [ ] **Step 6: Run, expect failure**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/FromServiceResolverTest.php
```

- [ ] **Step 7: Write `FromServiceResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Override;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * @psalm-api
 *
 * Recognises #[FromService(Id::class)] and resolves via the PSR-11 container.
 * The service id is captured in the payload at compile time; at request time
 * we look it up in the container present on InvocationContext::$services.
 *
 * Throws at resolve time if no container is wired.
 */
final readonly class FromServiceResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $attrs = $param->getAttributes(FromService::class);

        if ($attrs === []) {
            return null;
        }

        $serviceId = $attrs[0]->newInstance()->id;
        $type = $param->getType() instanceof ReflectionNamedType
            ? $param->getType()->getName()
            : null;

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: $type,
            payload: ['serviceId' => $serviceId],
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if ($ctx->services->container === null) {
            throw new RuntimeException(
                "Cannot resolve #[FromService] param \${$metadata->name}: no PSR-11 container wired",
            );
        }

        /** @var string $serviceId */
        $serviceId = $metadata->payload['serviceId'];

        return $ctx->services->container->get($serviceId);
    }
}
```

- [ ] **Step 8: Re-run FromServiceResolver tests**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/FromServiceResolverTest.php
```

Expected: `OK (3 tests, ...)`.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-http/src/Handler/Resolver/Builtin/FromActorResolver.php \
        packages/nexus-http/src/Handler/Resolver/Builtin/FromServiceResolver.php \
        packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/
git -c commit.gpgsign=false commit -m "feat(http): FromActorResolver + FromServiceResolver

Two attribute-driven resolvers. FromActor handles both singleton and per-
request actors; throws PerRequestActorInConstructorException when used in
HttpBoot scope. FromService delegates to the PSR-11 container wired on
ResolverServices."
```

---

## Task 6: FromBodyResolver + PathParamResolver

Two request-bound resolvers. Both return null in `HttpBoot` scope.

**Files:**
- Create: `packages/nexus-http/src/Handler/Resolver/Builtin/FromBodyResolver.php`
- Create: `packages/nexus-http/src/Handler/Resolver/Builtin/PathParamResolver.php`
- Create: `packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/FromBodyResolverTest.php`
- Create: `packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/PathParamResolverTest.php`

- [ ] **Step 1: Write `FromBodyResolverTest.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use LogicException;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromBodyResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Serialization\MessageSerializer;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(FromBodyResolver::class)]
final class FromBodyResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_no_attribute(): void
    {
        $resolver = new FromBodyResolver();
        $fn = static function (string $x): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpRequest, null)));
    }

    #[Test]
    public function returns_null_at_http_boot_scope_even_with_attribute(): void
    {
        $resolver = new FromBodyResolver();
        $fn = static function (#[FromBody] FakeDto $dto): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot, $this->serializer())));
    }

    #[Test]
    public function throws_when_no_type_hint(): void
    {
        $resolver = new FromBodyResolver();
        $fn = static function (#[FromBody] $dto): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $this->expectException(LogicException::class);
        $resolver->compile($param, $this->ctx(Scope::HttpRequest, $this->serializer()));
    }

    #[Test]
    public function throws_when_no_serializer_wired(): void
    {
        $resolver = new FromBodyResolver();
        $fn = static function (#[FromBody] FakeDto $dto): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $this->expectException(LogicException::class);
        $resolver->compile($param, $this->ctx(Scope::HttpRequest, null));
    }

    #[Test]
    public function resolve_deserializes_body_via_message_serializer(): void
    {
        $expected = new FakeDto('hi');
        $serializer = new class ($expected) implements MessageSerializer {
            public function __construct(private FakeDto $dto) {}
            #[Override]
            public function serialize(object $message): string { return ''; }
            #[Override]
            public function deserialize(string $data, string $type): object { return $this->dto; }
        };

        $resolver = new FromBodyResolver();
        $fn = static function (#[FromBody] FakeDto $dto): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $metadata = $resolver->compile($param, $this->ctx(Scope::HttpRequest, $serializer));
        self::assertNotNull($metadata);

        $resolved = $resolver->resolve(
            $metadata,
            new HttpRequestContext(
                new ResolverServices(new ResolvedActorTable([], []), null, $serializer),
                (new ServerRequest('POST', '/'))->withBody((new \Nyholm\Psr7\Factory\Psr17Factory())->createStream('{}')),
                [],
                new PerRequestActorScope(new ResolvedActorTable([], [])),
            ),
        );

        self::assertSame($expected, $resolved);
    }

    private function ctx(Scope $scope, ?MessageSerializer $serializer): CompileContext
    {
        return new CompileContext(
            $scope,
            'Owner',
            new ResolverServices(new ResolvedActorTable([], []), null, $serializer),
        );
    }

    private function serializer(): MessageSerializer
    {
        return new class implements MessageSerializer {
            #[Override]
            public function serialize(object $message): string { return ''; }
            #[Override]
            public function deserialize(string $data, string $type): object { return new FakeDto(''); }
        };
    }
}

final readonly class FakeDto
{
    public function __construct(public string $value) {}
}
```

- [ ] **Step 2: Write `FromBodyResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use LogicException;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Recognises #[FromBody] and deserializes the request body into the typed
 * DTO via the configured MessageSerializer. Only valid in request-bound
 * scopes (skips HttpBoot).
 *
 * Throws at compile time if the parameter lacks a class type hint or no
 * MessageSerializer is wired.
 */
final readonly class FromBodyResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $attrs = $param->getAttributes(FromBody::class);

        if ($attrs === []) {
            return null;
        }

        if (!$ctx->isRequestBound()) {
            return null;
        }

        $type = $param->getType() instanceof ReflectionNamedType
            ? $param->getType()->getName()
            : null;

        if ($type === null) {
            throw new LogicException(
                "Cannot resolve {$ctx->owner} param \${$param->getName()} via #[FromBody] — no class type hint",
            );
        }

        if ($ctx->services->serializer === null) {
            throw new LogicException(
                "{$ctx->owner} param \${$param->getName()} uses #[FromBody] but no MessageSerializer is wired. "
                . 'Call HttpApp::withMessageSerializer(...) at boot.',
            );
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: $type);
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if (!$ctx instanceof RequestBoundContext) {
            throw new LogicException(
                "FromBodyResolver invoked outside a request-bound context for \${$metadata->name}",
            );
        }

        /** @var string $type */
        $type = $metadata->type;
        /** @var \Monadial\Nexus\Serialization\MessageSerializer $serializer */
        $serializer = $ctx->services->serializer;

        return $serializer->deserialize((string) $ctx->request->getBody(), $type);
    }
}
```

- [ ] **Step 3: Write `PathParamResolverTest.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PathParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(PathParamResolver::class)]
final class PathParamResolverTest extends TestCase
{
    #[Test]
    public function returns_metadata_for_string_param_in_request_scope(): void
    {
        $resolver = new PathParamResolver();
        $fn = static function (string $orderId): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $metadata = $resolver->compile($param, $this->ctx(Scope::HttpRequest));
        self::assertNotNull($metadata);

        $resolved = $resolver->resolve(
            $metadata,
            new HttpRequestContext(
                $this->services(),
                new ServerRequest('GET', '/orders/42'),
                ['orderId' => '42'],
                new PerRequestActorScope(new ResolvedActorTable([], [])),
            ),
        );

        self::assertSame('42', $resolved);
    }

    #[Test]
    public function returns_null_for_non_string_type(): void
    {
        $resolver = new PathParamResolver();
        $fn = static function (int $orderId): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpRequest)));
    }

    #[Test]
    public function returns_null_in_http_boot_scope(): void
    {
        $resolver = new PathParamResolver();
        $fn = static function (string $orderId): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot)));
    }

    #[Test]
    public function missing_path_param_resolves_to_empty_string(): void
    {
        $resolver = new PathParamResolver();
        $fn = static function (string $orderId): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $metadata = $resolver->compile($param, $this->ctx(Scope::HttpRequest));
        self::assertNotNull($metadata);

        $resolved = $resolver->resolve(
            $metadata,
            new HttpRequestContext(
                $this->services(),
                new ServerRequest('GET', '/orders'),
                [],   // no orderId
                new PerRequestActorScope(new ResolvedActorTable([], [])),
            ),
        );

        self::assertSame('', $resolved);
    }

    private function ctx(Scope $scope): CompileContext
    {
        return new CompileContext($scope, 'Owner', $this->services());
    }

    private function services(): ResolverServices
    {
        return new ResolverServices(new ResolvedActorTable([], []));
    }
}
```

- [ ] **Step 4: Write `PathParamResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Recognises string-typed parameters in request-bound scopes as path
 * parameters. Looks up by parameter name in the route's matched path params.
 * Missing names resolve to empty string (matches existing HandlerResolver
 * behavior).
 */
final readonly class PathParamResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if (!$ctx->isRequestBound()) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string') {
            return null;
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: 'string');
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if (!$ctx instanceof RequestBoundContext) {
            return '';
        }

        return $ctx->pathParams[$metadata->name] ?? '';
    }
}
```

- [ ] **Step 5: Run both, expect green**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/FromBodyResolverTest.php packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/PathParamResolverTest.php
```

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http/src/Handler/Resolver/Builtin/FromBodyResolver.php \
        packages/nexus-http/src/Handler/Resolver/Builtin/PathParamResolver.php \
        packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/FromBodyResolverTest.php \
        packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/PathParamResolverTest.php
git -c commit.gpgsign=false commit -m "feat(http): FromBodyResolver + PathParamResolver

Both request-bound — return null in HttpBoot scope. FromBody throws on
missing type-hint or missing serializer. PathParam reads from
RequestBoundContext::\$pathParams; falls back to empty string on miss."
```

---

## Task 7: ServerRequestResolver + PerRequestScopeResolver

Two type-driven resolvers for the framework's own types.

**Files:**
- Create: `packages/nexus-http/src/Handler/Resolver/Builtin/ServerRequestResolver.php`
- Create: `packages/nexus-http/src/Handler/Resolver/Builtin/PerRequestScopeResolver.php`
- Create matching tests under `tests/Unit/Handler/Resolver/Builtin/`.

- [ ] **Step 1: Write `ServerRequestResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Resolves any parameter typed ServerRequestInterface to the current request,
 * but only in request-bound scopes. Skipped in HttpBoot (no request at boot).
 */
final readonly class ServerRequestResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if (!$ctx->isRequestBound()) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== ServerRequestInterface::class) {
            return null;
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: ServerRequestInterface::class);
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        /** @var RequestBoundContext $ctx */
        return $ctx->request;
    }
}
```

- [ ] **Step 2: Write `PerRequestScopeResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Resolves any parameter typed PerRequestActorScope. Only valid in
 * Scope::HttpRequest. PerRequestActorScope does not exist in WS connections.
 *
 * Sets needsScope=true so HandlerMetadata::needsRequestScope correctly
 * triggers per-request scope allocation upstream.
 */
final readonly class PerRequestScopeResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if ($ctx->scope !== Scope::HttpRequest) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== PerRequestActorScope::class) {
            return null;
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: PerRequestActorScope::class,
            needsScope: true,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        /** @var HttpRequestContext $ctx */
        return $ctx->perRequestScope;
    }
}
```

- [ ] **Step 3: Write `ServerRequestResolverTest.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ServerRequestResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;

#[CoversClass(ServerRequestResolver::class)]
final class ServerRequestResolverTest extends TestCase
{
    #[Test]
    public function returns_null_for_non_request_type(): void
    {
        $resolver = new ServerRequestResolver();
        $fn = static function (string $x): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpRequest)));
    }

    #[Test]
    public function returns_null_in_http_boot_scope(): void
    {
        $resolver = new ServerRequestResolver();
        $fn = static function (ServerRequestInterface $r): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot)));
    }

    #[Test]
    public function resolves_to_the_request_in_http_request_scope(): void
    {
        $resolver = new ServerRequestResolver();
        $fn = static function (ServerRequestInterface $r): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $metadata = $resolver->compile($param, $this->ctx(Scope::HttpRequest));
        self::assertNotNull($metadata);

        $req = new ServerRequest('GET', '/test');
        $resolved = $resolver->resolve(
            $metadata,
            new HttpRequestContext(
                $this->services(),
                $req,
                [],
                new PerRequestActorScope(new ResolvedActorTable([], [])),
            ),
        );

        self::assertSame($req, $resolved);
    }

    private function ctx(Scope $scope): CompileContext
    {
        return new CompileContext($scope, 'Owner', $this->services());
    }

    private function services(): ResolverServices
    {
        return new ResolverServices(new ResolvedActorTable([], []));
    }
}
```

- [ ] **Step 4: Write `PerRequestScopeResolverTest.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PerRequestScopeResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(PerRequestScopeResolver::class)]
final class PerRequestScopeResolverTest extends TestCase
{
    #[Test]
    public function returns_null_for_non_scope_type(): void
    {
        $resolver = new PerRequestScopeResolver();
        $fn = static function (string $x): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpRequest)));
    }

    #[Test]
    public function returns_null_in_http_boot_scope(): void
    {
        $resolver = new PerRequestScopeResolver();
        $fn = static function (PerRequestActorScope $s): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot)));
    }

    #[Test]
    public function returns_null_in_ws_connection_scope(): void
    {
        $resolver = new PerRequestScopeResolver();
        $fn = static function (PerRequestActorScope $s): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::WsConnection)));
    }

    #[Test]
    public function metadata_marks_needs_scope_true(): void
    {
        $resolver = new PerRequestScopeResolver();
        $fn = static function (PerRequestActorScope $s): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $metadata = $resolver->compile($param, $this->ctx(Scope::HttpRequest));

        self::assertNotNull($metadata);
        self::assertTrue($metadata->needsScope);
    }

    #[Test]
    public function resolves_to_the_per_request_scope(): void
    {
        $resolver = new PerRequestScopeResolver();
        $fn = static function (PerRequestActorScope $s): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $metadata = $resolver->compile($param, $this->ctx(Scope::HttpRequest));
        self::assertNotNull($metadata);

        $scope = new PerRequestActorScope(new ResolvedActorTable([], []));
        $resolved = $resolver->resolve(
            $metadata,
            new HttpRequestContext(
                $this->services(),
                new ServerRequest('GET', '/'),
                [],
                $scope,
            ),
        );

        self::assertSame($scope, $resolved);
    }

    private function ctx(Scope $scope): CompileContext
    {
        return new CompileContext($scope, 'Owner', $this->services());
    }

    private function services(): ResolverServices
    {
        return new ResolverServices(new ResolvedActorTable([], []));
    }
}
```

- [ ] **Step 4: Run all four resolver tests, expect green**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/
```

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/src/Handler/Resolver/Builtin/ServerRequestResolver.php \
        packages/nexus-http/src/Handler/Resolver/Builtin/PerRequestScopeResolver.php \
        packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/ServerRequestResolverTest.php \
        packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/PerRequestScopeResolverTest.php
git -c commit.gpgsign=false commit -m "feat(http): ServerRequestResolver + PerRequestScopeResolver

Type-driven resolvers for framework types. ServerRequest skips HttpBoot;
PerRequestScope is HttpRequest-only. PerRequestScope sets needsScope=true
so HandlerMetadata flags it for scope allocation."
```

---

## Task 8: ContainerFallbackResolver

The constructor fallback — when no other resolver matches and the parameter has a class type-hint bound in the PSR-11 container.

**Files:**
- Create: `packages/nexus-http/src/Handler/Resolver/Builtin/ContainerFallbackResolver.php`
- Create: `packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/ContainerFallbackResolverTest.php`

- [ ] **Step 1: Write `ContainerFallbackResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * @psalm-api
 *
 * Last-resort constructor fallback: when no other resolver matches and the
 * parameter has a class type-hint bound in the container, resolve via
 * container->get($type).
 *
 * Only active in constructor scopes (HttpBoot, WsConnection). HttpRequest
 * fall-through is a misuse — the existing HandlerResolver throws there too.
 */
final readonly class ContainerFallbackResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if ($ctx->scope === Scope::HttpRequest) {
            return null;
        }

        if ($ctx->services->container === null) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        if (!$ctx->services->container->has($type->getName())) {
            return null;
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: $type->getName(),
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if ($ctx->services->container === null) {
            throw new RuntimeException(
                "ContainerFallbackResolver invoked without a container for \${$metadata->name}",
            );
        }

        /** @var string $type */
        $type = $metadata->type;

        return $ctx->services->container->get($type);
    }
}
```

- [ ] **Step 2: Write `ContainerFallbackResolverTest.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ContainerFallbackResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpBootContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionFunction;

#[CoversClass(ContainerFallbackResolver::class)]
final class ContainerFallbackResolverTest extends TestCase
{
    #[Test]
    public function returns_null_in_http_request_scope(): void
    {
        $resolver = new ContainerFallbackResolver();
        $fn = static function (LoggerInterface $log): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpRequest, $this->container())));
    }

    #[Test]
    public function returns_null_when_no_container_wired(): void
    {
        $resolver = new ContainerFallbackResolver();
        $fn = static function (LoggerInterface $log): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot, null)));
    }

    #[Test]
    public function returns_null_for_builtin_type(): void
    {
        $resolver = new ContainerFallbackResolver();
        $fn = static function (string $x): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot, $this->container())));
    }

    #[Test]
    public function returns_null_when_container_lacks_binding(): void
    {
        $emptyContainer = new class implements ContainerInterface {
            public function get(string $id): mixed { throw new \LogicException(); }
            public function has(string $id): bool { return false; }
        };

        $resolver = new ContainerFallbackResolver();
        $fn = static function (LoggerInterface $log): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot, $emptyContainer)));
    }

    #[Test]
    public function resolves_via_container_when_binding_present(): void
    {
        $logger = new NullLogger();
        $container = new class ($logger) implements ContainerInterface {
            public function __construct(private LoggerInterface $logger) {}
            public function get(string $id): mixed { return $this->logger; }
            public function has(string $id): bool { return $id === LoggerInterface::class; }
        };

        $resolver = new ContainerFallbackResolver();
        $fn = static function (LoggerInterface $log): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];

        $metadata = $resolver->compile($param, $this->ctx(Scope::HttpBoot, $container));
        self::assertNotNull($metadata);

        $resolved = $resolver->resolve(
            $metadata,
            new HttpBootContext(new ResolverServices(new ResolvedActorTable([], []), $container)),
        );

        self::assertSame($logger, $resolved);
    }

    private function ctx(Scope $scope, ?ContainerInterface $container): CompileContext
    {
        return new CompileContext(
            $scope,
            'Owner',
            new ResolverServices(new ResolvedActorTable([], []), $container),
        );
    }

    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed { return new NullLogger(); }
            public function has(string $id): bool { return true; }
        };
    }
}
```

- [ ] **Step 3: Run, expect green**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/ContainerFallbackResolverTest.php
```

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http/src/Handler/Resolver/Builtin/ContainerFallbackResolver.php \
        packages/nexus-http/tests/Unit/Handler/Resolver/Builtin/ContainerFallbackResolverTest.php
git -c commit.gpgsign=false commit -m "feat(http): ContainerFallbackResolver — constructor fallback via PSR-11

Active only in HttpBoot/WsConnection scopes. Returns null silently if no
container, no class type-hint, or no container binding for the type."
```

---

### Phase 1.5 done — all 802 tests still green

```bash
docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=unit 2>&1 | tail -3
```

Expected: same 802 test count, all green. The new resolver classes are not yet wired into anything, so behavior is unchanged.

---

## Task 9: Wire ParamResolverRegistry into HandlerResolver

The boldest change: replace `describeParams` / `buildArgs` / `instantiate` to use the registry instead of the hard-coded `if/elseif` chain. The new `ParamMetadata` (with resolver back-ref) replaces the old `ParamMetadata` (with `KIND_*` constants).

**Files:**
- Modify: `packages/nexus-http/src/Handler/HandlerResolver.php` (significant)
- Delete (in this commit): `packages/nexus-http/src/Handler/ParamMetadata.php` (the OLD class with KIND_* constants)
- Modify: anything currently consuming the old `ParamMetadata` — discover via `grep`.

- [ ] **Step 1: Find all consumers of the OLD ParamMetadata**

```bash
docker compose exec -T php-fiber grep -rl "use Monadial\\\\Nexus\\\\Http\\\\Handler\\\\ParamMetadata" packages/
docker compose exec -T php-fiber grep -rl "ParamMetadata::KIND_" packages/
```

Expected references: `HandlerResolver.php`, possibly `HandlerMetadata.php`, possibly tests under `packages/nexus-http/tests/Unit/Handler/`. Note the list — we update them all in this task.

- [ ] **Step 2: Inject the registry into HandlerResolver's constructor**

Edit `packages/nexus-http/src/Handler/HandlerResolver.php`:

Add to the constructor:

```php
public function __construct(
    private readonly ResolvedActorTable $actors,
    private readonly ?ContainerInterface $container,
    private readonly ?MessageSerializer $serializer = null,
    private readonly ?ParamResolverRegistry $registry = null,  // NEW
) {}
```

Add a getter/lazy-init for the effective registry:

```php
private function registry(): ParamResolverRegistry
{
    if ($this->registry !== null) {
        return $this->registry;
    }

    // Default registry — same order as the old if/elseif chain.
    return (new ParamResolverRegistry())
        ->with(new FromActorResolver())
        ->with(new FromBodyResolver())
        ->with(new FromServiceResolver())
        ->with(new ServerRequestResolver())
        ->with(new PerRequestScopeResolver())
        ->with(new PathParamResolver())
        ->with(new ContainerFallbackResolver());
}
```

Build a `ResolverServices` once and reuse:

```php
private function services(): ResolverServices
{
    return new ResolverServices($this->actors, $this->container, $this->serializer);
}
```

Add the imports at the top of the file:

```php
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ContainerFallbackResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromActorResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromBodyResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromServiceResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PathParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PerRequestScopeResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ServerRequestResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpBootContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata as NewParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
```

(`NewParamMetadata` alias avoids collision with the old `ParamMetadata` import during the brief window where both exist in the file's imports. Removed in Step 4.)

- [ ] **Step 3: Replace `describeParams()` body**

```php
/**
 * @param array<int, ReflectionParameter> $params
 * @return list<NewParamMetadata>
 */
private function describeParams(array $params, bool $inConstructor, string $owner): array
{
    $ctx = new CompileContext(
        $inConstructor ? Scope::HttpBoot : Scope::HttpRequest,
        $owner,
        $this->services(),
    );

    $registry = $this->registry();

    return array_map(
        static fn($p) => $registry->compile($p, $ctx),
        $params,
    );
}
```

- [ ] **Step 4: Replace `buildArgs()` body**

```php
/**
 * @param list<NewParamMetadata> $params
 * @param array<string, string> $pathParams
 * @return list<mixed>
 */
private function buildArgs(
    array $params,
    ServerRequestInterface $r,
    PerRequestActorScope $scope,
    array $pathParams,
): array {
    $ctx = new HttpRequestContext($this->services(), $r, $pathParams, $scope);

    return array_map(
        static fn($p) => $p->resolver->resolve($p, $ctx),
        $params,
    );
}
```

- [ ] **Step 5: Replace `instantiate()` param resolution**

The instantiate() method previously had its own `match($p->kind)` switch for constructor params. Replace it with:

```php
/**
 * @param class-string $class
 * @param list<NewParamMetadata> $ctorParams
 */
private function instantiate(string $class, array $ctorParams): object
{
    if ($this->container !== null && $this->container->has($class)) {
        /** @var object */
        return $this->container->get($class);
    }

    $bootCtx = new HttpBootContext($this->services());
    $args = array_map(
        static fn($p) => $p->resolver->resolve($p, $bootCtx),
        $ctorParams,
    );

    /** @psalm-suppress MixedMethodCall */
    return new $class(...$args);
}
```

- [ ] **Step 6: Delete the OLD `ParamMetadata.php`**

```bash
git rm packages/nexus-http/src/Handler/ParamMetadata.php
```

Also remove `use Monadial\Nexus\Http\Handler\ParamMetadata;` from `HandlerResolver.php` if present, and any `KIND_*` switch arms from `paramsNeedScope()` (replaced below).

- [ ] **Step 7: Replace `paramsNeedScope()` to read the new flag**

```php
/** @param list<NewParamMetadata> $params */
private function paramsNeedScope(array $params): bool
{
    foreach ($params as $p) {
        if ($p->needsScope) {
            return true;
        }
    }

    return false;
}
```

- [ ] **Step 8: Update `HandlerMetadata.php` if it imports old ParamMetadata**

`HandlerMetadata` carries `$ctorParams` and `$invokeParams` as `list<ParamMetadata>`. Update its docblock and `use` statements to point to the new namespace:

```php
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
```

Then drop the import alias — rename `NewParamMetadata` back to plain `ParamMetadata` in `HandlerResolver.php` (since the OLD one is now deleted, no collision).

- [ ] **Step 9: Run the existing nexus-http test suite**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/
```

Expected: all 104 nexus-http tests stay green. If any fail, the new resolvers' behavior diverges from the old logic — diagnose and fix.

**Common failures to expect:**
- `HandlerResolverFromPrincipalTest` (from earlier T8) will FAIL because `#[FromPrincipal]` is no longer hard-coded in HandlerResolver. **That's expected** — Phase 4 (T14-T15) re-enables it via FromPrincipalResolver. For now, mark this test skipped:
  ```php
  self::markTestSkipped('Re-enabled in T15 after FromPrincipalResolver registration');
  ```
- `HandlerResolverTest` — should stay green; the seven built-in resolvers exactly cover the old behavior for `#[FromActor]`, `#[FromBody]`, `#[FromService]`, type-based ServerRequest / PerRequestActorScope, string path params, and container fallback.

- [ ] **Step 10: Commit**

```bash
git add packages/nexus-http/src/Handler/HandlerResolver.php \
        packages/nexus-http/src/Handler/HandlerMetadata.php \
        packages/nexus-http/tests/Unit/Handler/HandlerResolverFromPrincipalTest.php
git rm packages/nexus-http/src/Handler/ParamMetadata.php
git -c commit.gpgsign=false commit -m "refactor(http): HandlerResolver uses ParamResolverRegistry

Replaces the hard-coded if/elseif chain in describeParams() / buildArgs() /
instantiate() with a registry of seven built-in ParamResolvers. Old
ParamMetadata (with KIND_* constants) is deleted; new ParamMetadata (with
resolver back-ref) takes its place.

HandlerResolverFromPrincipalTest is temporarily skipped — re-enabled in
T15 when nexus-http-auth's FromPrincipalResolver lands."
```

---

## Task 10: HandlerResolver regression gate

Re-run all tests across all packages to make sure nothing else broke.

- [ ] **Step 1: Run the full unit suite**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
```

Expected: 801 tests pass (one skipped via T9 — the FromPrincipal test pending T15). If anything else breaks, diagnose and fix BEFORE moving on.

- [ ] **Step 2: Run Psalm to check types**

```bash
docker compose exec -T php-fiber vendor/bin/psalm --no-cache packages/nexus-http/src 2>&1 | tail -5
```

Expected: no new errors in `nexus-http/src` (pre-existing errors in other packages are fine).

- [ ] **Step 3: Run PHPCS + PHP-CS-Fixer on the refactored code**

```bash
docker compose exec -T php-fiber vendor/bin/phpcs packages/nexus-http/src/Handler/ 2>&1 | tail -3
docker compose exec -T php-fiber vendor/bin/php-cs-fixer fix packages/nexus-http/src/Handler/ --dry-run --diff 2>&1 | tail -3
```

Fix anything that reports — phpcbf and php-cs-fixer auto-resolve most issues:

```bash
docker compose exec -T php-fiber vendor/bin/phpcbf packages/nexus-http/src/Handler/
docker compose exec -T php-fiber vendor/bin/php-cs-fixer fix packages/nexus-http/src/Handler/
```

- [ ] **Step 4: Commit any fixups**

```bash
git add packages/nexus-http/
git -c commit.gpgsign=false commit -m "style(http): post-refactor lint fixes" --allow-empty
```

If `git status` shows no changes, use `--allow-empty` (or skip the commit). The phase boundary marker is enough.

---

### Phase 2 done — HandlerResolver fully on the registry

- 104 nexus-http tests + new resolver tests all green
- Old `ParamMetadata.php` deleted; KIND_* constants gone from the codebase
- One test temporarily skipped (FromPrincipal), pending Phase 4

---

## Task 11: WsConnectionContext + FromContextResolver (nexus-http-ws)

Two new files in `nexus-http-ws`. `WsConnectionContext` extends `RequestBoundContext` and adds the WebSocketContext. `FromContextResolver` handles `#[FromContext]` (and implicit WebSocketContext type-hints).

**Files:**
- Create: `packages/nexus-http-ws/src/WebSocket/Resolver/WsConnectionContext.php`
- Create: `packages/nexus-http-ws/src/WebSocket/Resolver/FromContextResolver.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/Resolver/FromContextResolverTest.php`

- [ ] **Step 1: Make directories**

```bash
mkdir -p packages/nexus-http-ws/src/WebSocket/Resolver
mkdir -p packages/nexus-http-ws/tests/Unit/WebSocket/Resolver
```

- [ ] **Step 2: Write `WsConnectionContext.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket\Resolver;

use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Resolution context active during WebSocketHandler construction. Carries the
 * per-connection WebSocketContext that #[FromContext] (and implicit type-hint
 * resolution) read.
 *
 * Lives in nexus-http-ws because nexus-http cannot reference WebSocketContext
 * without taking a hard dep on nexus-http-ws (the reverse of the actual dep
 * direction).
 */
final readonly class WsConnectionContext extends RequestBoundContext
{
    /**
     * @param array<string, string> $pathParams
     */
    public function __construct(
        ResolverServices $services,
        ServerRequestInterface $request,
        array $pathParams,
        public WebSocketContext $wsContext,
    ) {
        parent::__construct(Scope::WsConnection, $services, $request, $pathParams);
    }
}
```

- [ ] **Step 3: Write `FromContextResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket\Resolver;

use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Override;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * @psalm-api
 *
 * Resolves #[FromContext] WebSocketContext parameters on WebSocketHandler
 * constructors. Only valid in Scope::WsConnection.
 *
 * Replaces the hard-coded check in the old HandlerInstantiator::resolveParam().
 */
final readonly class FromContextResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if ($ctx->scope !== Scope::WsConnection) {
            return null;
        }

        if (count($param->getAttributes(FromContext::class)) === 0) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== WebSocketContext::class) {
            throw new RuntimeException(
                "#[FromContext] on {$ctx->owner}::__construct(\${$param->getName()}) requires "
                . 'parameter type ' . WebSocketContext::class . '.',
            );
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: WebSocketContext::class,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        /** @var WsConnectionContext $ctx */
        return $ctx->wsContext;
    }
}
```

- [ ] **Step 4: Write `FromContextResolverTest.php`**

Following the same shape as earlier resolver tests:
- Returns null when not in WsConnection scope
- Returns null when no #[FromContext] attribute
- Throws on wrong type
- Returns metadata + resolves to wsContext correctly

- [ ] **Step 5: Run, expect green**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/Resolver/FromContextResolverTest.php
```

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http-ws/src/WebSocket/Resolver/ packages/nexus-http-ws/tests/Unit/WebSocket/Resolver/
git -c commit.gpgsign=false commit -m "feat(http-ws): WsConnectionContext + FromContextResolver

WsConnectionContext extends nexus-http's RequestBoundContext, carries the
per-connection WebSocketContext. FromContextResolver replaces the
hard-coded #[FromContext] check in the old HandlerInstantiator."
```

---

## Task 12: Refactor HandlerInstantiator to use the registry

Replace `resolveParam()` with registry-driven dispatch. Add a per-class compile cache so reflection doesn't run on every connection.

**Files:**
- Modify: `packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php`

- [ ] **Step 1: Add ParamResolverRegistry field + default initialization**

Add to the constructor:

```php
public function __construct(
    private readonly ContainerInterface $container,
    ?LoggerInterface $logger = null,
    ?ParamResolverRegistry $registry = null,                 // NEW
    private readonly ?ResolvedActorTable $actors = null,     // NEW (for ResolverServices)
    private readonly ?MessageSerializer $serializer = null,  // NEW
) {
    $this->logger = $logger ?? new NullLogger();
    $this->registry = $registry;
}
```

Add the lazy-init helper:

```php
private function registry(): ParamResolverRegistry
{
    if ($this->registry !== null) {
        return $this->registry;
    }

    return (new ParamResolverRegistry())
        ->with(new FromActorResolver())
        ->with(new FromServiceResolver())
        ->with(new PathParamResolver())
        ->with(new ServerRequestResolver())
        ->with(new ContainerFallbackResolver())
        ->with(new FromContextResolver());
}

private function services(): ResolverServices
{
    return new ResolverServices(
        $this->actors ?? new ResolvedActorTable([], []),
        $this->container,
        $this->serializer,
    );
}
```

- [ ] **Step 2: Replace `resolveParam()` and surrounding logic**

The new `instantiate()` method:

```php
/**
 * @param class-string<WebSocketHandler> $handlerClass
 */
public function instantiate(string $handlerClass, WebSocketContext $ctx): WebSocketHandler
{
    $ref = new ReflectionClass($handlerClass);
    $ctorParams = $ref->getConstructor()?->getParameters() ?? [];

    $compileCtx = new CompileContext(Scope::WsConnection, $handlerClass, $this->services());

    /** @var list<ParamMetadata> $metadata */
    $metadata = array_map(
        fn($p) => $this->registry()->compile($p, $compileCtx),
        $ctorParams,
    );

    $invocationCtx = new WsConnectionContext(
        $this->services(),
        $ctx->request(),
        // Path parameters land in the request attributes via the route match.
        // Extract them here from $ctx->request()->getAttributes() if needed.
        $this->extractPathParams($ctx->request()),
        $ctx,
    );

    $args = array_map(
        static fn(ParamMetadata $m) => $m->resolver->resolve($m, $invocationCtx),
        $metadata,
    );

    return new $handlerClass(...$args);
}

/** @return array<string, string> */
private function extractPathParams(ServerRequestInterface $request): array
{
    // nexus-http-ws's WebSocketDispatcher (see WebSocket/WebSocketDispatcher.php)
    // stamps path parameters onto the request as individual attributes named
    // exactly as they appear in the route pattern (e.g. {room} → 'room').
    // Read all string attributes and treat them as path params. This is the
    // same convention HandlerResolver uses on the HTTP side via the route
    // matcher's `pathParams` array.
    $out = [];

    /** @var array<string, mixed> $all */
    $all = $request->getAttributes();

    foreach ($all as $key => $value) {
        if (is_string($value) && !str_starts_with($key, '_')) {
            $out[$key] = $value;
        }
    }

    return $out;
}
```

**Important:** before committing, OPEN `packages/nexus-http-ws/src/WebSocket/WebSocketDispatcher.php` and verify the actual convention. If the dispatcher uses a single `_pathParams` array attribute instead of individual ones, change the extractor accordingly:

```php
private function extractPathParams(ServerRequestInterface $request): array
{
    /** @var mixed $params */
    $params = $request->getAttribute('_pathParams');

    return is_array($params) ? $params : [];
}
```

Pick whichever the dispatcher already uses — don't invent a new convention.

- [ ] **Step 3: Remove the old `resolveParam()` method entirely**

```bash
docker compose exec -T php-fiber grep -n "private function resolveParam" packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php
```

Delete the method.

- [ ] **Step 4: Run the nexus-http-ws test suite**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http-ws/tests/
```

Expected: 50 nexus-http-ws tests stay green PLUS the new FromContextResolver test. **Exception**: `HandlerInstantiatorFromPrincipalTest` (from T10) will FAIL since the FQCN-string hack is replaced by the new pipeline. Mark it skipped — T15 re-enables it.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php \
        packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorFromPrincipalTest.php
git -c commit.gpgsign=false commit -m "refactor(http-ws): HandlerInstantiator uses ParamResolverRegistry

resolveParam() deleted in favor of registry-driven dispatch shared with
HandlerResolver. WsConnectionContext + 7 built-in resolvers (incl. WS
FromContextResolver) cover all previous behavior.

HandlerInstantiatorFromPrincipalTest skipped — re-enabled in T15."
```

---

### Phase 3 done — HandlerInstantiator on the registry

- All nexus-http-ws tests pass (one skipped)
- Both HandlerResolver and HandlerInstantiator share the same `ParamResolver` interface and resolver classes
- FromActor / FromService / PathParam / ServerRequest / ContainerFallback all work in WS contexts now (they didn't before)

---

## Task 13: Add FromPrincipalResolver in nexus-http-auth

The whole motivation. Replace the FQCN-string hack with a real resolver. Works in both HTTP and WS via the shared registry.

**Files:**
- Create: `packages/nexus-http-auth/src/Resolver/FromPrincipalResolver.php`
- Create: `packages/nexus-http-auth/tests/Unit/Resolver/FromPrincipalResolverTest.php`

- [ ] **Step 1: Write `FromPrincipalResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Resolver;

use LogicException;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Recognises #[FromPrincipal] and reads the 'principal' request attribute
 * stamped by AuthenticationMiddleware.
 *
 * Only valid in request-bound scopes (HttpRequest, WsConnection). Throws at
 * compile time if used in HttpBoot — the principal is per-request, not
 * per-handler-instance.
 *
 * Register via $app->paramResolver(new FromPrincipalResolver()) in the
 * application bootstrap. The same resolver instance serves HTTP and WS
 * handlers automatically.
 */
final readonly class FromPrincipalResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $attrs = $param->getAttributes(FromPrincipal::class);

        if ($attrs === []) {
            return null;
        }

        if (!$ctx->isRequestBound()) {
            throw new LogicException(
                "Cannot resolve {$ctx->owner}::__construct(\${$param->getName()}) via #[FromPrincipal] — "
                . 'principal is per-request; declare it on __invoke() instead (HTTP) or use '
                . 'the WebSocketHandler constructor (which runs per-connection).',
            );
        }

        $type = $param->getType() instanceof ReflectionNamedType
            ? $param->getType()->getName()
            : null;

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: $type);
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if (!$ctx instanceof RequestBoundContext) {
            throw new LogicException(
                "FromPrincipalResolver invoked outside a request-bound context for \${$metadata->name}",
            );
        }

        $principal = $ctx->request->getAttribute('principal');

        if ($principal === null) {
            throw new LogicException(
                "Handler requested #[FromPrincipal] but no Principal on request — "
                . 'register AuthenticationMiddleware globally.',
            );
        }

        return $principal;
    }
}
```

- [ ] **Step 2: Write `FromPrincipalResolverTest.php`**

Tests:
- Returns null when no #[FromPrincipal] attribute
- Throws when used in HttpBoot scope
- Returns metadata in HttpRequest scope
- Returns metadata in WsConnection scope
- Resolves to the request attribute correctly
- Throws when principal attribute is missing at request time

- [ ] **Step 3: Run, expect green**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Resolver/FromPrincipalResolverTest.php
```

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-http-auth/src/Resolver/ packages/nexus-http-auth/tests/Unit/Resolver/
git -c commit.gpgsign=false commit -m "feat(http-auth): FromPrincipalResolver

Real ParamResolver implementation for #[FromPrincipal] — replaces the
FQCN-string hacks that used to live in nexus-http/HandlerResolver and
nexus-http-ws/HandlerInstantiator (removed in T15).

Works in both HTTP and WS via the shared ParamResolverRegistry. Throws at
compile time when used in HttpBoot scope (principal is per-request)."
```

---

## Task 14: Remove FQCN-string hacks from nexus-http and nexus-http-ws

Delete the lines we added in T8 (FromPrincipal in HandlerResolver) and T10 (FromPrincipal in HandlerInstantiator).

**Files:**
- Modify: `packages/nexus-http/src/Handler/HandlerResolver.php` — already largely refactored in T9; verify nothing references FQCN-string still
- Modify: `packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php` — already largely refactored in T12; verify nothing references FQCN-string still

- [ ] **Step 1: Search both files for any remaining `FromPrincipal` references**

```bash
grep -n "FromPrincipal" packages/nexus-http/src/Handler/HandlerResolver.php
grep -n "FromPrincipal" packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php
```

Expected: no matches in either file. The new design removed them automatically when the resolvers were refactored.

If any references remain (e.g., a leftover `getAttributes('Monadial\\Nexus\\…\\FromPrincipal')` call), DELETE them. The new flow handles `#[FromPrincipal]` exclusively through `FromPrincipalResolver` registered at the app layer.

Also remove `KIND_FROM_PRINCIPAL` if it still exists anywhere:

```bash
grep -rn "KIND_FROM_PRINCIPAL" packages/
```

Expected: no matches (T9 deleted the old ParamMetadata where this lived).

- [ ] **Step 2: Re-run T8's `HandlerResolverFromPrincipalTest` after wiring the resolver**

This test was marked skipped in T9. Now that FromPrincipalResolver exists, the test should pass IF the resolver is registered.

Add the resolver registration to the test setup:

```php
// In HandlerResolverFromPrincipalTest:
private function makeResolver(): HandlerResolver
{
    $registry = (new ParamResolverRegistry())
        ->with(new FromActorResolver())
        ->with(new FromBodyResolver())
        ->with(new FromServiceResolver())
        ->with(new ServerRequestResolver())
        ->with(new PerRequestScopeResolver())
        ->with(new PathParamResolver())
        ->with(new ContainerFallbackResolver())
        ->with(new FromPrincipalResolver());  // explicit for the test

    return new HandlerResolver(
        new ResolvedActorTable([], []),
        null,
        null,
        $registry,
    );
}
```

Remove the `self::markTestSkipped(...)` line.

- [ ] **Step 3: Same for `HandlerInstantiatorFromPrincipalTest`**

Update the test to register `FromPrincipalResolver` in the registry passed to `HandlerInstantiator`.

- [ ] **Step 4: Run both tests, expect green**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/HandlerResolverFromPrincipalTest.php
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorFromPrincipalTest.php
```

Expected: both pass.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http/tests/Unit/Handler/HandlerResolverFromPrincipalTest.php \
        packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorFromPrincipalTest.php
git -c commit.gpgsign=false commit -m "refactor(http,http-ws): remove FromPrincipal FQCN-string hacks

The hacks were the temporary bridge from T8/T10. Now that the
ParamResolver registry is in place and nexus-http-auth registers a
real FromPrincipalResolver, nothing in nexus-http or nexus-http-ws
references auth concerns by string anymore.

The two FromPrincipal regression tests previously skipped in T9/T12
are re-enabled with explicit FromPrincipalResolver registration."
```

---

## Task 15: User extension API + nexus-http-auth integration test update

Add `HttpApp::paramResolver()` so users can register custom resolvers. Update the auth package's integration test to use the new explicit registration.

**Files:**
- Modify: `packages/nexus-http/src/Dsl/HttpApp.php` — add `paramResolver()` method
- Modify: `packages/nexus-http-auth/tests/Integration/HttpAuthIntegrationTest.php` — register FromPrincipalResolver explicitly
- (Optionally) Modify: `packages/nexus-http-ws/src/HttpApplication.php` / `WsApplication.php` to expose the same method (delegated to HttpApp).

- [ ] **Step 1: Add `paramResolver()` to HttpApp**

```php
// In HttpApp.php:

/** @var list<ParamResolver> */
private array $paramResolvers = [];

public function paramResolver(ParamResolver $resolver, bool $override = false): self
{
    if ($override) {
        $this->paramResolvers = [$resolver, ...$this->paramResolvers];
    } else {
        $this->paramResolvers[] = $resolver;
    }

    return $this;
}
```

Then at `compile()` time, build the final registry by starting from defaults and applying the user's resolvers:

```php
private function buildRegistry(): ParamResolverRegistry
{
    $registry = (new ParamResolverRegistry())
        ->with(new FromActorResolver())
        ->with(new FromBodyResolver())
        ->with(new FromServiceResolver())
        ->with(new ServerRequestResolver())
        ->with(new PerRequestScopeResolver())
        ->with(new PathParamResolver())
        ->with(new ContainerFallbackResolver());

    foreach ($this->paramResolvers as $resolver) {
        $registry = $registry->with($resolver);
    }

    return $registry;
}
```

Pass this registry into the `HandlerResolver` at `compile()` time.

- [ ] **Step 2: Mirror on HttpApplication / WsApplication (nexus-http-ws)**

In `HttpApplication`:

```php
public function paramResolver(ParamResolver $resolver, bool $override = false): self
{
    $this->inner->paramResolver($resolver, $override);

    return $this;
}
```

In `WsApplication::decorate(...)`: forward the resolver registration to the inner HttpApplication, AND also register `FromContextResolver` as a default for WS apps.

- [ ] **Step 3: Update the nexus-http-auth integration test**

Replace the buildApp() method:

```php
private function buildApp(): CompiledApplication
{
    $system = ActorSystem::create('http-auth-test', new StepRuntime());

    $auth = new InMemoryAuthenticator([
        'k_alice' => new SimplePrincipal('alice', scopes: ['orders.read']),
        'k_bob'   => new SimplePrincipal('bob', scopes: ['orders.read', 'orders.write']),
    ]);

    $app = HttpApplication::create($system)
        ->middleware(new AuthenticationMiddleware($auth))
        ->paramResolver(new FromPrincipalResolver());

    $app->get('/health', static fn() => Response::ok());
    $app->get('/me', MeHandler::class)
        ->middleware(AuthorizationMiddleware::class);
    $app->post('/orders', CreateOrderHandler::class)
        ->middleware(AuthorizationMiddleware::class);

    return $app->compile();
}
```

- [ ] **Step 4: Run the integration test, expect green**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Integration/
```

Expected: all 5 integration tests pass.

- [ ] **Step 5: Run the full nexus-http-auth suite**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http-auth/
```

Expected: 47 tests pass plus the new FromPrincipalResolver test.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http/src/Dsl/HttpApp.php \
        packages/nexus-http-ws/src/HttpApplication.php \
        packages/nexus-http-ws/src/WsApplication.php \
        packages/nexus-http-auth/tests/Integration/HttpAuthIntegrationTest.php
git -c commit.gpgsign=false commit -m "feat(http,http-ws,http-auth): paramResolver() extension API

HttpApp/HttpApplication/WsApplication gain ->paramResolver(\$resolver,
override: bool) for user-defined ParamResolvers. The default registry
contains the seven built-in resolvers; user resolvers are appended (or
prepended with override: true).

nexus-http-auth's integration test now uses
->paramResolver(new FromPrincipalResolver()) — no FQCN-string hacks
anywhere in the codebase."
```

---

## Task 16: Documentation updates

Reflect the new architecture in the user-facing docs.

**Files:**
- Modify: `website/docs/packages/http.md` — document `paramResolver()` and the resolver pattern
- Modify: `website/docs/http/handlers.md` — show writing a custom resolver
- Modify: `website/docs/packages/http-auth.md` — update FromPrincipal section to show explicit registration

- [ ] **Step 1: Add a "Custom Param Resolvers" section to `http.md`**

Cover: what a ParamResolver is, when to write one, the compile/resolve contract, where it fits (HTTP vs WS — they share!), the seven built-ins as reference, the `paramResolver()` API.

Code example: a hypothetical `#[FromHeader('X-Trace-Id')]` resolver, ~20 lines, showing how it's registered.

- [ ] **Step 2: Add the same as a tutorial section in `http/handlers.md`**

A walkthrough — "How to inject custom data into handler params":
1. Define an attribute class
2. Implement `ParamResolver`
3. Register via `$app->paramResolver(new MyResolver())`
4. Use the attribute in handler params

- [ ] **Step 3: Update `http-auth.md` to show explicit registration**

Replace any text saying "automatically wired" or "framework knows about #[FromPrincipal]" with the new explicit call:

```php
$app->paramResolver(new FromPrincipalResolver());
```

Mention that this works for both HTTP and WS handlers from the same registration.

- [ ] **Step 4: Verify the docs site builds**

```bash
cd website && npm start > /tmp/docs.log 2>&1 &
DOCS_PID=$!
sleep 10
curl -s -o /dev/null -w "http=%{http_code} http-auth=%{http_code} handlers=%{http_code}\n" \
    http://localhost:3000/docs/packages/http \
    http://localhost:3000/docs/packages/http-auth \
    http://localhost:3000/docs/http/handlers
kill $DOCS_PID 2>/dev/null
```

Expected: three 200s.

- [ ] **Step 5: Commit**

```bash
git add website/docs/packages/http.md website/docs/packages/http-auth.md website/docs/http/handlers.md
git -c commit.gpgsign=false commit -m "docs: ParamResolver registry, custom resolver tutorial, FromPrincipal registration"
```

---

## Task 17: Final full-repo regression gate

Make sure nothing else broke.

- [ ] **Step 1: Full unit suite across all packages**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=unit 2>&1 | tail -3
```

Expected: 802+ tests pass (we added ~45 new resolver unit tests). 4 may be skipped (Swoole-context tests). No failures.

- [ ] **Step 2: Integration tests**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Integration/
```

Expected: all 5 integration scenarios green.

- [ ] **Step 3: Psalm across the whole repo**

```bash
docker compose exec -T php-fiber vendor/bin/psalm --no-cache 2>&1 | tail -5
```

Expected: same baseline pre-existing errors (Swoole-related in nexus-runtime-swoole / nexus-app), NO new errors introduced by the refactor.

- [ ] **Step 4: PHPCS + Deptrac**

```bash
docker compose exec -T php-fiber vendor/bin/phpcs packages/nexus-http packages/nexus-http-ws packages/nexus-http-auth 2>&1 | tail -5
docker compose exec -T php-fiber php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac 2>&1 | tail -3
```

Expected: PHPCS clean (auto-fix anything via `phpcbf`). Deptrac shows 0 violations — specifically verify that `nexus-http` has no edge pointing to `nexus-http-auth` or `nexus-http-ws`.

- [ ] **Step 5: Wrap-up commit if anything was fixed**

```bash
git status   # should be clean OR have minor lint fixes
git log --oneline 9bdee6a8..HEAD | head -20   # review the refactor commits
```

Optionally an `--allow-empty` marker commit:

```bash
git -c commit.gpgsign=false commit --allow-empty -m "chore: handler resolver redesign complete — all gates green"
```

---

### Phase 5 done — full refactor landed, all 802+ tests green

The new architecture is live:
- One `ParamResolver` interface in `nexus-http`
- One `ParamResolverRegistry`, shared between HTTP and WS dispatchers
- Seven built-in resolvers in `nexus-http`, one in `nexus-http-ws`, one in `nexus-http-auth`
- Polymorphic dispatch via `$metadata->resolver->resolve()` — no central `match($kind)` statement
- `$app->paramResolver()` lets users add their own
- nexus-http and nexus-http-ws no longer reference auth concerns

---

## Verification: spec coverage checklist

Walk through the spec one more time:

- [x] `ParamResolver` interface + `ParamMetadata` (new) + `Scope` enum + `CompileContext` + `InvocationContext` hierarchy + `ResolverServices` + `ParamResolverRegistry` + `UnresolvableParameterException` → T1-T4
- [x] Seven built-in resolvers (`FromActorResolver`, `FromServiceResolver`, `FromBodyResolver`, `PathParamResolver`, `ServerRequestResolver`, `PerRequestScopeResolver`, `ContainerFallbackResolver`) → T5-T8
- [x] `HandlerResolver` refactored to use registry → T9-T10
- [x] `WsConnectionContext` + `FromContextResolver` in nexus-http-ws → T11
- [x] `HandlerInstantiator` refactored to use registry → T12
- [x] `FromPrincipalResolver` in nexus-http-auth → T13
- [x] FQCN-string hacks removed from nexus-http + nexus-http-ws → T14
- [x] `$app->paramResolver()` user extension API → T15
- [x] Integration test updated to use explicit FromPrincipalResolver registration → T15
- [x] Documentation updated → T16
- [x] Full-repo regression gates → T10, T15, T17
- [x] All 802 existing unit tests stay green at every commit → enforced phase-by-phase
- [x] Same observable behavior — no public API breakage → resolver registry is additive; old behavior preserved by built-in resolvers; FromPrincipal moves to explicit registration (one-line addition to user code, documented in T16)

All spec requirements have an implementing task.

---

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Phase 2 commit breaks the existing nexus-http test suite | Each step has an explicit "run tests" gate. If anything fails, fix before the commit. The seven built-in resolvers are designed to exactly cover the old `if/elseif` branches. |
| `HandlerMetadata.needsRequestScope` aggregation changes behavior | T9 adds `$p->needsScope` flag to ParamMetadata; the existing `paramsNeedScope()` aggregator reads it. Same signal, same result. |
| Slevomat / Psalm complaints on the new resolver pattern | Most resolvers are <60 lines and follow the same shape; if Psalm complains about polymorphic dispatch or anonymous-class tests, add `@psalm-suppress` inline. Project convention. |
| `nexus-http-ws` accidentally introduces a hard dep back into `nexus-http-auth` | The FQCN-string hack lives only in test files (which can `use` the auth class — `tests/` isn't deptrac-gated). Source files in `nexus-http-ws/src/` MUST NOT `use` anything from `nexus-http-auth`. T14 explicitly verifies. |
| Deptrac discovers new violations | Run `deptrac` after Phase 2 and Phase 3; fix any layer violations before the phase ends. |
