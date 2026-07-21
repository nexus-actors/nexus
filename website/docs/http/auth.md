---
sidebar_position: 8
title: Authentication & Authorization
related:
  - http/handlers
  - http/middleware
  - http/overview
  - packages/http-auth
---

# Authentication & Authorization

Auth lives in a separate package — `nexus-actors/http-auth` — because not every Nexus HTTP application needs it, and the choices it makes (which authenticator, which `Principal` shape, which policy mechanism) are opinionated. Keeping it separate lets the core HTTP stack stay small and lets you swap individual pieces independently.

The package contributes:

- A contract surface: `Principal`, `Authenticator`, `Authorizer`, `TokenExtractor`.
- A JWT authenticator built on lcobucci/jwt ^5.
- Two middleware: `AuthenticationMiddleware`, `AuthorizationMiddleware`.
- Seven attributes for declarative route protection.
- A `SimplePrincipal` default implementation.

## Bearer + JWT setup

The minimal end-to-end setup with JWT:

```php title="server.php"
<?php

declare(strict_types=1);

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Monadial\Nexus\Http\Auth\Attribute\{FromPrincipal, RequiresAuth};
use Monadial\Nexus\Http\Auth\Authenticator\JwtAuthenticator;
use Monadial\Nexus\Http\Auth\Middleware\{AuthenticationMiddleware, AuthorizationMiddleware};
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Ws\HttpApplication;

$jwt = Configuration::forSymmetricSigner(
    new Sha256(),
    InMemory::plainText(getenv('JWT_SECRET')),
);

$auth = new JwtAuthenticator(
    $jwt,
    claimsMapper: static fn(Plain $t) => new SimplePrincipal(
        id: (string) $t->claims()->get('sub'),
        scopes: explode(' ', (string) $t->claims()->get('scope', '')),
    ),
    // Always constrain issuer AND audience when a signing key is shared
    // across issuers, services, or tenants — otherwise a correctly signed
    // token minted for another service is accepted here (cross-service replay).
    issuers: ['https://auth.example.com'],
    audience: 'orders-api',
    // Optional: require a specific subject, and allow small clock skew.
    // subject: 'service-account',
    // leeway: new \DateInterval('PT30S'),
);

$app = HttpApplication::create($system)
    ->middleware(new AuthenticationMiddleware($auth));

$app->get('/me', MeHandler::class)
    ->middleware(AuthorizationMiddleware::class);
```

A request to `/me` with `Authorization: Bearer <jwt>` returns the principal payload; without a valid JWT it returns `401`.

## Reading the principal in a handler

`#[FromPrincipal]` injects the current `Principal` from the request attribute that `AuthenticationMiddleware` populates. Use it on the `__invoke()` parameter list, not the constructor — handler instances are constructed once at boot, but the `Principal` is per-request:

```php title="src/Http/Handler/MeHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\{FromPrincipal, RequiresAuth};
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Response\JsonResponse;

#[RequiresAuth]
final class MeHandler
{
    public function __invoke(#[FromPrincipal] Principal $me): JsonResponse
    {
        return JsonResponse::ok([
            'id'     => $me->id(),
            'scopes' => $me->scopes(),
        ]);
    }
}
```

If you only need the principal in middleware or without injection, read `$req->getAttribute('principal')` — the same value, untyped.

## Scope checks

OAuth-style scopes are the most common policy. Two attributes:

```php title="src/Http/Handler/UpdateOrderHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\{RequiresAnyScope, RequiresScope};

#[RequiresScope('orders:read', 'orders:write')]     // all-of: must have BOTH
final class UpdateOrderHandler { /* … */ }

#[RequiresAnyScope('orders:read', 'orders:admin')]  // any-of: must have AT LEAST ONE
final class ListOrdersHandler { /* … */ }
```

The check happens in `AuthorizationMiddleware` after route matching. Missing scopes return `403` with the missing scope list:

```json
{ "error": "forbidden", "missing": ["orders:write"] }
```

Anonymous requests against a scope-protected route get `401`, not `403` — there is no `Principal` to compare scopes against.

## Role checks

Roles share the same shape as scopes:

```php title="src/Http/Handler/AdminDashboardHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\{RequiresAnyRole, RequiresRole};

#[RequiresRole('admin')]
final class AdminDashboardHandler { /* must be admin */ }

#[RequiresAnyRole('admin', 'support')]
final class SupportConsoleHandler { /* admin OR support */ }
```

Roles and scopes are independent. The `Principal` interface declares both because most authentication backends distinguish them: scopes from OAuth, roles from a directory.

## Custom policies via `#[Authorize]`

When scope and role attributes aren't enough — ownership checks, multi-tenant isolation, time-of-day rules — implement `Authorizer`:

```php title="src/Http/Policy/OwnsOrderPolicy.php"
use Monadial\Nexus\Http\Auth\{Authorizer, Principal};
use Psr\Http\Message\ServerRequestInterface;

final class OwnsOrderPolicy implements Authorizer
{
    public function __construct(
        private readonly OrderRepository $repo,
    ) {}

    public function authorize(Principal $principal, ServerRequestInterface $req): bool
    {
        $orderId = (string) $req->getAttribute('id');
        $order   = $this->repo->find($orderId);

        return $order !== null && $order->ownerId === $principal->id();
    }
}
```

Attach it to a handler with `#[Authorize]`:

```php title="src/Http/Handler/ShowOrderHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\Authorize;

#[Authorize(OwnsOrderPolicy::class)]
final class ShowOrderHandler { /* … */ }
```

The framework resolves `OwnsOrderPolicy` from the PSR-11 container, calls `authorize()`, and turns `false` into a `403`. Stack policies by listing several `#[Authorize]` attributes; the middleware runs them in order and short-circuits on the first `false`.

## Custom `Principal` implementations

`SimplePrincipal` covers most use cases. Implement `Principal` directly when you want to carry a domain object:

```php title="src/Auth/User.php"
use Monadial\Nexus\Http\Auth\Principal;

final readonly class User implements Principal
{
    public function __construct(
        public string $id,
        public string $email,
        public string $tenantId,
        /** @var list<string> */ public array $scopes,
    ) {}

    public function id(): string              { return $this->id; }
    public function roles(): array            { return []; }
    public function scopes(): array           { return $this->scopes; }
    public function claims(): array           { return ['tenant' => $this->tenantId]; }
    public function hasRole(string $r): bool  { return false; }
    public function hasScope(string $s): bool { return in_array($s, $this->scopes, true); }
}
```

Return your type from the claims mapper, then type-hint it in handlers:

```php title="src/Http/Handler/ProfileHandler.php"
public function __invoke(#[FromPrincipal] User $me): JsonResponse
{
    return JsonResponse::ok(['email' => $me->email, 'tenant' => $me->tenantId]);
}
```

## WebSocket auth

A WebSocket upgrade is an HTTP request, and it is authorized **before** the 101 protocol switch: the pre-upgrade handshake gate runs the WebSocket middleware pipeline against the upgrade request and rejects unauthorized connections with plain HTTP responses (401/403) — the connection is never upgraded. Register auth middleware with `wsMiddleware()`, share `FromPrincipalResolver` via `paramResolver()`, and decorate the handler with `#[RequiresAuth]` (or `#[RequiresScope]`/`#[RequiresRole]`):

```php title="src/Http/Handler/PrivateChatHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\{FromPrincipal, RequiresAuth};
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\{WebSocketContext, WebSocketFrame, WebSocketHandler};

#[RequiresAuth]
final class PrivateChatHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext]   private readonly WebSocketContext $ctx,
        #[FromPrincipal] private readonly Principal $me,
    ) {}

    #[\Override]
    public function onOpen(): void
    {
        $this->ctx->send("hello, {$this->me->id()}");
    }

    #[\Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send("{$this->me->id()}: {$frame->text}");
    }
}
```

```php title="server.php"
$app = WsApplication::create($system)
    ->paramResolver(new FromPrincipalResolver())              // shared with WS handlers
    ->wsMiddleware(new AuthenticationMiddleware($auth))       // every WS upgrade
    ->wsMiddleware(new AuthorizationMiddleware())             // enforces handler attributes
    ->ws('/ws/private', PrivateChatHandler::class);
```

Unlike HTTP routes, `AuthorizationMiddleware` **may** be registered as global WebSocket middleware: the handshake gate resolves the route and stamps `_resolvedHandlerClass` before the pipeline runs, so the middleware always sees the matched handler class. Per-route middleware is also supported as the third argument of `ws()` (fifth of `channel()`) and runs after the global WS middleware.

The `Principal` is captured at upgrade time — the gate hands the authenticated request to the open dispatch, so `#[FromPrincipal]` constructor parameters resolve to the same principal that authorized the handshake. It lives for the duration of the connection; for revocation semantics, track session IDs at the application layer and close the connection when revoked.

## Why per-route `AuthorizationMiddleware`?

`AuthorizationMiddleware` reads class attributes off the matched handler class. It needs the `_resolvedHandlerClass` request attribute that `RouterMiddleware` sets during route matching.

In PSR-15, global middleware runs before `RouterMiddleware`. Registering `AuthorizationMiddleware` globally means it fires before the router and sees no resolved handler class — it silently lets every request through. That is the worst possible failure mode for an auth library, so the package enforces the per-route pattern.

```php title="server.php"
$app = HttpApplication::create($system)
    ->middleware(new AuthenticationMiddleware($auth));      // global

$app->get('/me', MeHandler::class)->middleware(AuthorizationMiddleware::class);
$app->post('/orders', CreateOrderHandler::class)->middleware(AuthorizationMiddleware::class);
```

If you find yourself repeating `->middleware(AuthorizationMiddleware::class)` on every route, factor it into a builder helper.

## Cross-site protection (Origin allow-list)

Browsers attach cookies to cross-site requests and WebSocket upgrades automatically, and neither has CORS preflight protection — so a cookie bearer token alone is exposed to CSRF and cross-site WebSocket hijacking (CSWSH). Verify the request `Origin` against an **exact** allow-list with `OriginAllowlistMiddleware` from `nexus-http-toolkit`:

```php title="server.php"
use Monadial\Nexus\Http\Toolkit\Middleware\OriginAllowlistMiddleware;

$origin = new OriginAllowlistMiddleware(['https://app.example.com'], allowMissingOrigin: false);

// WebSocket upgrades: runs in the pre-upgrade handshake, rejects before the 101.
$app->wsMiddleware($origin);

// State-changing HTTP routes:
$app->post('/transfer', TransferHandler::class)->middleware($origin);
```

Matching is exact on scheme + host + port — `https://app.example.com` never matches `https://app.example.com.evil.com`, `http://app.example.com`, or a different port. WebSocket upgrades arrive as GET and are checked (GET is not exempt by default). When you set a cookie bearer token, also mark it `HttpOnly`, `Secure`, `SameSite=Strict`/`Lax`, and keep a CSRF token for defense in depth.

## Compilation fails closed

Forgetting the route-level `AuthorizationMiddleware` is no longer a silent hole. `compile()` verifies every route whose handler class declares an auth attribute (`#[RequiresAuth]`, `#[RequiresScope]`, `#[RequiresRole]`, `#[RequiresAnyScope]`, `#[RequiresAnyRole]`, `#[Authorize]`) actually has an authorization enforcer in its middleware — and throws `UnprotectedRouteException` at startup otherwise. Registering `AuthorizationMiddleware` globally fails compilation too (`GlobalAuthorizationMiddlewareException`), since global middleware runs before routing.

The check is marker-driven: the attributes implement `Monadial\Nexus\Http\Security\AuthorizationRequirement` and `AuthorizationMiddleware` implements `Monadial\Nexus\Http\Security\AuthorizationEnforcer`. Custom authorization middleware should implement the enforcer marker to satisfy the check; custom requirement attributes get the same fail-closed protection by implementing the requirement marker. WebSocket routes are checked the same way against their `wsMiddleware()`/per-route pipeline.

## See also

- [Handlers](./handlers.md) — `#[FromPrincipal]` and the resolver pipeline.
- [Middleware](./middleware.md) — global vs per-route registration.
- [`nexus-http-auth`](../packages/http-auth.md) — full attribute, authenticator, and extractor reference.
