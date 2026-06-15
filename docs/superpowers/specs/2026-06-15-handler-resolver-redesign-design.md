# Handler Resolver Redesign Design

**Status:** Spec — pending review
**Author:** brainstorming session 2026-06-15
**Target packages:** `nexus-actors/http`, `nexus-actors/http-ws`, `nexus-actors/http-auth`

## Goal

Replace the hard-coded `if/elseif` chains in `nexus-http/HandlerResolver::describeParams()` (90 lines) and `nexus-http-ws/HandlerInstantiator::resolveParam()` (50 lines) with a **single shared, extensible `ParamResolver` interface and registry**. The same resolver class works for both HTTP and WebSocket handlers. Third-party packages (starting with `nexus-http-auth`) register their `#[From*]` attribute resolvers without patching core. `nexus-http-auth`'s FQCN-string hacks in HandlerResolver and HandlerInstantiator are removed.

## Out of scope

- Public API of the existing `#[FromActor]` / `#[FromBody]` / `#[FromService]` / `#[FromContext]` attributes — they keep their behavior; only the dispatch internals change.
- `Route::handler` shape (`string|Closure`) and `RouterMiddleware` behavior — already touched in T9 and unaffected here.
- The `Future<ResponseInterface>` handling in `HandlerResolver::postProcess()` — orthogonal to param resolution.
- Performance characteristics of the resolution path — the new design is no worse than the old (reflection still runs once per handler class, results cached).

## Public surface

All in `nexus-http/src/Handler/Resolver/`:

```php
namespace Monadial\Nexus\Http\Handler\Resolver;

interface ParamResolver
{
    /**
     * Decide whether to handle this parameter. Return ParamMetadata if yes,
     * null to defer to the next resolver. Throws only on configuration errors
     * (e.g. #[FromBody] without a type hint).
     */
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata;

    /**
     * Resolve the metadata to a value at request time. Framework only calls
     * this with metadata that THIS resolver produced (polymorphic dispatch
     * via $metadata->resolver back-ref).
     */
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed;
}

final readonly class ParamMetadata
{
    public function __construct(
        public ParamResolver $resolver,        // back-ref for polymorphic dispatch
        public string $name,
        public ?string $type,
        public array $payload = [],            // resolver-specific opaque data
        public bool $needsScope = false,       // framework-level hint
    ) {}
}

enum Scope
{
    case HttpBoot;        // HTTP handler constructor (once, at boot)
    case HttpRequest;     // HTTP handler __invoke (per-request)
    case WsConnection;    // WebSocketHandler constructor (per-connection)
}

final readonly class CompileContext
{
    public function __construct(
        public Scope $scope,
        public string $owner,                   // class name for error messages
        public ResolverServices $services,      // container, serializer, actors
    ) {}

    public function isRequestBound(): bool
    {
        return $this->scope !== Scope::HttpBoot;
    }
}

final readonly class ResolverServices
{
    public function __construct(
        public ResolvedActorTable $actors,
        public ?ContainerInterface $container = null,
        public ?MessageSerializer $serializer = null,
    ) {}
}

abstract readonly class InvocationContext
{
    public function __construct(
        public Scope $scope,
        public ResolverServices $services,
    ) {}
}

final readonly class HttpBootContext extends InvocationContext
{
    // No request-bound fields. Services only.
    public function __construct(ResolverServices $services)
    {
        parent::__construct(Scope::HttpBoot, $services);
    }
}

abstract readonly class RequestBoundContext extends InvocationContext
{
    public function __construct(
        Scope $scope,
        ResolverServices $services,
        public ServerRequestInterface $request,
        public array $pathParams,
    ) {
        parent::__construct($scope, $services);
    }
}

final readonly class HttpRequestContext extends RequestBoundContext
{
    public function __construct(
        ResolverServices $services,
        ServerRequestInterface $request,
        array $pathParams,
        public PerRequestActorScope $perRequestScope,
    ) {
        parent::__construct(Scope::HttpRequest, $services, $request, $pathParams);
    }
}

final readonly class WsConnectionContext extends RequestBoundContext
{
    public function __construct(
        ResolverServices $services,
        ServerRequestInterface $request,
        array $pathParams,
        public WebSocketContext $wsContext,
    ) {
        parent::__construct(Scope::WsConnection, $services, $request, $pathParams);
    }
}

final class ParamResolverRegistry
{
    /** @param list<ParamResolver> $resolvers */
    public function __construct(private array $resolvers = []) {}

    /** Returns a new registry with the resolver APPENDED (built-ins win first match). */
    public function with(ParamResolver $resolver): self
    {
        return new self([...$this->resolvers, $resolver]);
    }

    /** Returns a new registry with the resolver PREPENDED (user wins; used to override). */
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

final class UnresolvableParameterException extends NexusException
{
    public static function forParameter(ReflectionParameter $param, CompileContext $ctx): self
    {
        // Enumerates which attributes / types the framework knows about so
        // users see "add #[FromActor], #[FromService], or type-hint a class
        // bound in the container" rather than a generic error.
    }
}
```

## Built-in resolvers

All in `nexus-http/src/Handler/Resolver/Builtin/`. Each is ~30-60 lines, single responsibility.

### `FromActorResolver`

```
Trigger:       #[FromActor('name')]
Scopes:        All. Per-request actors at HttpBoot scope throw PerRequestActorInConstructorException.
Compile:       Reads name from attribute, validates against ResolverServices->actors.
Payload:       ['actorName' => string]
Needs scope:   True iff actor is per-request.
Resolve:       Returns either the singleton ActorRef or scope->spawn(name) per-request.
```

### `FromServiceResolver`

```
Trigger:       #[FromService(Id::class)]
Scopes:        All. Throws if no container available.
Compile:       Reads service id from attribute.
Payload:       ['serviceId' => string]
Resolve:       container->get(serviceId).
```

### `FromBodyResolver`

```
Trigger:       #[FromBody] $dto
Scopes:        RequestBound only (skips HttpBoot — no request at boot).
Compile:       Requires class type hint, requires serializer to be wired. Throws otherwise.
Payload:       (none — type stays on metadata)
Resolve:       serializer->deserialize((string) request->getBody(), $metadata->type).
```

### `PathParamResolver`

```
Trigger:       string-typed parameter (no attribute needed); current
               HandlerResolver convention.
Scopes:        RequestBound only. Returns null in HttpBoot.
Compile:       Returns null for non-string types or HttpBoot scope.
Payload:       (none — name on metadata is sufficient)
Resolve:       ctx->pathParams[$metadata->name] ?? '' (matches existing
               HandlerResolver behavior).
```

### `ServerRequestResolver`

```
Trigger:       type-hint ServerRequestInterface.
Scopes:        RequestBound only.
Resolve:       ctx->request.
```

### `PerRequestScopeResolver`

```
Trigger:       type-hint PerRequestActorScope.
Scopes:        HttpRequest only (PerRequestActorScope only exists in HTTP request flow).
Resolve:       ctx->perRequestScope (HttpRequestContext only).
```

### `ContainerFallbackResolver`

```
Trigger:       Constructor-only fallback. Class type hint that's bound in the container.
Scopes:        HttpBoot, WsConnection (constructor scopes only).
Compile:       Returns null if container has no binding for this type.
Resolve:       container->get($metadata->type).
```

### `FromContextResolver` (in nexus-http-ws)

```
Trigger:       #[FromContext] (or implicit by type-hint WebSocketContext)
Scopes:        WsConnection only. Returns null in other scopes.
Resolve:       ctx->wsContext (WsConnectionContext only).
```

### `FromPrincipalResolver` (in nexus-http-auth)

```
Trigger:       #[FromPrincipal]
Scopes:        RequestBound only. Returns null in HttpBoot (with helpful message).
Compile:       Throws LogicException if registered on a non-request-bound scope.
Resolve:       request->getAttribute('principal'); throws if absent.
```

## How the registry is assembled

`HttpApp` (and by extension `HttpApplication` / `WsApplication`) holds **one** `ParamResolverRegistry`. The default registry is assembled in `HttpApp::create()`:

```php
$registry = new ParamResolverRegistry();
$registry = $registry
    ->with(new FromActorResolver($actors))
    ->with(new FromServiceResolver($container))
    ->with(new FromBodyResolver($serializer))
    ->with(new PathParamResolver())
    ->with(new ServerRequestResolver())
    ->with(new PerRequestScopeResolver())
    ->with(new ContainerFallbackResolver($container));
```

When `WsApplication::decorate()` wraps an `HttpApplication`, it adds the WS-only resolver:

```php
$registry = $registry->with(new FromContextResolver());
```

Both `HandlerResolver` (HTTP) and `HandlerInstantiator` (WS) consume the same registry. The framework hands out the registry via the existing PSR-11 container, or via a new `ParamResolverRegistryAware` constructor argument on those two classes.

**User extension API:**

```php
$app->paramResolver(new MyCustomResolver());                      // appended
$app->paramResolver(new MyOverridingResolver(), override: true);   // prepended
```

Both calls go through `HttpApp` which updates its internal registry. The change propagates to `HandlerResolver` and `HandlerInstantiator` because they read the registry from the compiled application.

**`nexus-http-auth` bootstrap:**

```php
// In nexus-http-auth — users add this once to their app bootstrap:
$app->paramResolver(new FromPrincipalResolver());
```

This registers the resolver for **both** HTTP and WS handlers automatically. `nexus-http-auth` no longer needs the FQCN-string hack in nexus-http or nexus-http-ws — those workarounds are deleted by this redesign.

## Consumers (refactor of existing classes)

### `HandlerResolver::describeParams()` (nexus-http)

**Before:** 90-line if/elseif chain hard-coding 4 attribute types + 3 type-based cases.

**After:**

```php
private function describeParams(
    array $params,
    bool $inConstructor,
    string $owner,
): array {
    $scope = $inConstructor ? Scope::HttpBoot : Scope::HttpRequest;
    $ctx = new CompileContext($scope, $owner, $this->services);

    return array_map(
        fn($p) => $this->registry->compile($p, $ctx),
        $params,
    );
}
```

**`buildArgs()` shrinks correspondingly:**

```php
private function buildArgs(
    array $metadata,
    ServerRequestInterface $r,
    PerRequestActorScope $scope,
    array $pathParams,
): array {
    $ctx = new HttpRequestContext($this->services, $r, $pathParams, $scope);

    return array_map(
        fn($m) => $m->resolver->resolve($m, $ctx),
        $metadata,
    );
}
```

**`instantiate()` becomes similarly thin** — passes `HttpBootContext`.

### `HandlerInstantiator::resolveParam()` (nexus-http-ws)

**Before:** 50-line if/elseif chain over 2 attributes + 3 fallbacks.

**After:**

```php
private function instantiate(string $handlerClass, WebSocketContext $wsCtx): WebSocketHandler
{
    $ref = new ReflectionClass($handlerClass);
    $ctorParams = $ref->getConstructor()?->getParameters() ?? [];

    $compileCtx = new CompileContext(Scope::WsConnection, $handlerClass, $this->services);
    $metadata = array_map(
        fn($p) => $this->registry->compile($p, $compileCtx),
        $ctorParams,
    );

    $invocationCtx = new WsConnectionContext(
        $this->services,
        $wsCtx->request(),
        $this->pathParamsOf($wsCtx->request()),
        $wsCtx,
    );

    $args = array_map(
        fn($m) => $m->resolver->resolve($m, $invocationCtx),
        $metadata,
    );

    return new $handlerClass(...$args);
}
```

The metadata can be cached per handler class to avoid re-reflection per connection. Optional optimization.

## Migration plan

This refactor touches three packages and many tests. The migration is structured to keep tests green at every step.

**Phase 1 — Add the new contracts in nexus-http (additive, no behavior change):**
1. Add the new types under a NEW namespace `Monadial\Nexus\Http\Handler\Resolver\`: `ParamResolver` interface, `ParamMetadata` value object, `Scope` enum, `CompileContext`, `InvocationContext` hierarchy, `ResolverServices`, `ParamResolverRegistry`, `UnresolvableParameterException`.
2. Add seven built-in resolver classes under `Handler/Resolver/Builtin/`.
3. Tests for each resolver in isolation.

**Coexistence during migration:** the existing `Monadial\Nexus\Http\Handler\ParamMetadata` (with `KIND_*` constants) stays untouched through Phase 1. The new namespace `Handler\Resolver\` carries the new value object. Old and new ParamMetadata coexist briefly. Phase 2 migrates `HandlerResolver` to consume the new types and deletes the old class.

**Phase 2 — Switch HandlerResolver to use the registry:**
1. Add a `ParamResolverRegistry` field to `HandlerResolver`. Default-initialise it with the built-in resolvers when no registry is injected.
2. Replace `describeParams()` body with `array_map(... registry.compile)`. Replace `buildArgs()` body with `array_map(... metadata.resolver.resolve)`. Replace `instantiate()`'s param resolution similarly.
3. Delete the OLD `Monadial\Nexus\Http\Handler\ParamMetadata` class and all `KIND_*` constant references.
4. Re-run nexus-http unit tests (104 tests should stay green). Fix anything that breaks.

**Phase 3 — Switch HandlerInstantiator to use the registry:**
1. Add a `ParamResolverRegistry` field. Initialise with built-ins plus `FromContextResolver`.
2. Replace `resolveParam()` body with the new flow.
3. Re-run nexus-http-ws unit tests (50 tests).

**Phase 4 — Remove the nexus-http-auth FQCN-string hacks:**
1. In nexus-http-auth, add `FromPrincipalResolver` implementing `ParamResolver`.
2. Add a tiny one-liner that the framework picks up automatically when `nexus-http-auth` is installed — either through a `ServiceProvider`-style bootstrap class, or through explicit user registration via `$app->paramResolver(new FromPrincipalResolver())`. Pick the latter — the simpler and more explicit option.
3. Delete the `$p->getAttributes('Monadial\\Nexus\\Http\\Auth\\Attribute\\FromPrincipal')` calls from nexus-http/HandlerResolver and nexus-http-ws/HandlerInstantiator. Delete the `KIND_FROM_PRINCIPAL` constant from `ParamMetadata` (already gone by Phase 2, but worth verifying).
4. Update `nexus-http-auth` docs to show the new `$app->paramResolver()` step.
5. Re-run nexus-http-auth tests (47 tests).

**Phase 5 — Full repo regression test:**
1. Run all 802 unit tests across all packages.
2. Verify deptrac is still clean.
3. Verify Psalm has no new errors introduced (the 80 pre-existing Swoole errors are unrelated).

Each phase commits independently. If any phase introduces a regression, the previous phases are still safe and the issue is localized.

## Error handling

### Compile-time errors (thrown from `registry.compile()`)

| Trigger | Exception | Where |
|---|---|---|
| No resolver claims the parameter | `UnresolvableParameterException` | registry, listing tried resolvers |
| `#[FromBody]` without class type hint | `LogicException` | `FromBodyResolver::compile` |
| `#[FromBody]` without serializer wired | `LogicException` | `FromBodyResolver::compile` |
| `#[FromActor]` referencing unknown actor | `UnknownActorException` | `FromActorResolver::compile` |
| Per-request actor in constructor scope | `PerRequestActorInConstructorException` | `FromActorResolver::compile` |
| `#[FromPrincipal]` at `HttpBoot` scope | `LogicException` | `FromPrincipalResolver::compile` |

All compile-time errors fire at handler resolve time — before the first request. They fail the boot, not the request.

### Runtime errors (thrown from `resolver.resolve()`)

| Trigger | Exception | Where |
|---|---|---|
| `#[FromBody]` deserialization fails | `MessageDeserializationException` (existing) | `FromBodyResolver::resolve` |
| `#[FromPrincipal]` but no principal on request | `AuthMiddlewareNotRegisteredException` | `FromPrincipalResolver::resolve` |
| `#[FromService]` service not in container | `NotFoundExceptionInterface` (PSR-11) | `FromServiceResolver::resolve` |

Runtime errors bubble through PSR-15 to the framework's `ExceptionHandlerMiddleware`, then to `onException` mappers if registered.

## Testing strategy

### New unit tests (~50 new tests)

Each built-in resolver gets its own test file covering:
- `compile()` returns ParamMetadata when triggered
- `compile()` returns null when not triggered
- `compile()` throws on misconfiguration (the right errors)
- `resolve()` returns the right value at request time

`ParamResolverRegistry`:
- First non-null wins (registration order)
- `with()` appends, `withOverride()` prepends
- Throws `UnresolvableParameterException` when nothing matches

### Regression suite

All 802 existing unit tests across the repo MUST stay green. The whole point of this refactor is that observable behavior is unchanged — only the internal dispatch mechanism is replaced.

Specifically:
- nexus-http: 104 tests (HandlerResolver, RouterMiddleware, all routing/middleware/handler tests)
- nexus-http-ws: 50 tests (HandlerInstantiator, WS dispatcher, route compilation)
- nexus-http-auth: 47 tests (all FromPrincipal-dependent tests must keep passing after migration)
- All other packages: untouched, must stay green

### Integration tests

`nexus-http-auth`'s existing integration test (5 scenarios) exercises the full pipeline including `#[FromPrincipal]` injection. It MUST pass after the refactor with the new `FromPrincipalResolver`.

## What this enables (future, no architecture change)

Documented as motivation, not in scope for this spec:

- `#[FromHeader('X-Trace-Id')]` — one resolver, works in HTTP and WS upgrade
- `#[FromQuery('page')]` — same
- `#[FromFrame] MyDto` for WS — register a resolver scoped to `WsConnection`, use the serializer from `ResolverServices`
- Custom auth Principal sources without forking `nexus-http-auth`

## Open questions (none expected)

None at this point. The design is concrete; the migration plan is sequenced; the test contract is explicit (802 tests must stay green).
