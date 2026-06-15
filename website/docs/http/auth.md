---
sidebar_position: 8
title: Authentication & Authorization
---

# Authentication & Authorization

Auth lives in a separate package — `nexus-actors/http-auth` — because not
every Nexus HTTP application needs it, and the choices it makes (which
authenticator, which Principal shape, which policy mechanism) are
opinionated. Keeping it out of `nexus-http` lets the core HTTP stack stay
small and lets you swap individual pieces without touching the framework.

The package contributes:

- A small contract surface (`Principal`, `Authenticator`, `Authorizer`,
  `TokenExtractor`).
- A JWT authenticator built on lcobucci/jwt ^5.
- Two middleware (`AuthenticationMiddleware`, `AuthorizationMiddleware`).
- Seven attributes for declarative protection of routes.
- A `SimplePrincipal` default implementation.

Everything else — extractor choice, Principal shape, policy logic — is
yours.

## Hello, JWT

The minimal end-to-end setup:

```php
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;
use Monadial\Nexus\Http\Auth\Authenticator\JwtAuthenticator;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Ws\HttpApplication;

// 1. Configure JWT (signer + key, validation handled by lcobucci/jwt).
$jwt = Configuration::forSymmetricSigner(
    new Sha256(),
    InMemory::plainText(getenv('JWT_SECRET')),
);

// 2. Build the authenticator. The claims-mapper closure decides what
//    Principal to return for each verified token.
$auth = new JwtAuthenticator(
    $jwt,
    claimsMapper: static fn(Plain $t) => new SimplePrincipal(
        id: (string) $t->claims()->get('sub'),
        scopes: explode(' ', (string) $t->claims()->get('scope', '')),
    ),
);

// 3. A handler that requires authentication.
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

// 4. Wire it up. AuthenticationMiddleware is global, AuthorizationMiddleware
//    is per-route.
$app = HttpApplication::create($system)
    ->middleware(new AuthenticationMiddleware($auth));

$app->get('/me', MeHandler::class)
    ->middleware(AuthorizationMiddleware::class);
```

That's all of it. A request to `/me` with `Authorization: Bearer <jwt>`
returns the principal payload; without a valid JWT it returns 401.

## Reading the Principal in a handler

`#[FromPrincipal]` injects the current `Principal` from the request
attribute that `AuthenticationMiddleware` populates. Use it on the
`__invoke()` parameter list, not the constructor — handler instances are
constructed once at boot (with their cached attribute metadata), but the
Principal is per-request:

```php
final class ShowOrderHandler
{
    public function __invoke(
        ServerRequestInterface $req,
        #[FromPrincipal] Principal $me,
    ): JsonResponse {
        return JsonResponse::ok([
            'id'    => $req->getAttribute('id'),
            'owner' => $me->id(),
        ]);
    }
}
```

If you only need to read the Principal in middleware or in a few places
without injection, fall back to `$req->getAttribute('principal')` — the
same value, untyped.

## Adding scope checks

OAuth-style scopes are the most common policy. Two attributes:

```php
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyScope;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;

#[RequiresScope('orders:read', 'orders:write')]    // all-of
final class UpdateOrderHandler { /* needs BOTH */ }

#[RequiresAnyScope('orders:read', 'orders:admin')] // any-of
final class ListOrdersHandler { /* needs AT LEAST ONE */ }
```

The check happens in `AuthorizationMiddleware` after route matching.
If the Principal is missing the required scopes, you get a `403` whose
body lists the missing scopes:

```json
{ "error": "forbidden", "missing": ["orders:write"] }
```

Anonymous requests against a scope-protected route get `401`, not `403`
— there's no Principal to compare scopes against.

## Adding role checks

Roles share the same shape as scopes:

```php
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyRole;
use Monadial\Nexus\Http\Auth\Attribute\RequiresRole;

#[RequiresRole('admin')]
final class AdminDashboardHandler { /* must be admin */ }

#[RequiresAnyRole('admin', 'support')]
final class SupportConsoleHandler { /* admin OR support */ }
```

Roles and scopes are independent — a Principal can have any combination.
The Principal interface declares both because most authentication backends
distinguish between them: scopes from OAuth, roles from a directory.

## Custom policies via #[Authorize]

When scope/role attributes aren't enough — ownership checks, multi-tenant
isolation, time-of-day rules — implement the `Authorizer` interface:

```php
use Monadial\Nexus\Http\Auth\Authorizer;
use Monadial\Nexus\Http\Auth\Principal;
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

```php
use Monadial\Nexus\Http\Auth\Attribute\Authorize;

#[Authorize(OwnsOrderPolicy::class)]
final class ShowOrderHandler { /* ... */ }
```

The framework resolves `OwnsOrderPolicy` from the PSR-11 container you
gave to `$app->withContainer(...)`, calls `authorize()`, and turns
`false` into a `403` with an empty `missing[]` — policy failures don't
enumerate a list of missing scopes because the failure isn't about scopes.

Stack policies by listing several `#[Authorize]` attributes; the middleware
runs them in order and short-circuits on the first `false`.

## Custom Principal implementations

`SimplePrincipal` covers most use cases, but you can implement `Principal`
directly when you want to carry a domain object:

```php
use Monadial\Nexus\Http\Auth\Principal;

final readonly class User implements Principal
{
    public function __construct(
        public string $id,
        public string $email,
        public string $tenantId,
        /** @var list<string> */ public array $scopes,
    ) {}

    public function id(): string     { return $this->id; }
    public function roles(): array   { return []; }
    public function scopes(): array  { return $this->scopes; }
    public function claims(): array  { return ['tenant' => $this->tenantId]; }
    public function hasRole(string $r): bool  { return false; }
    public function hasScope(string $s): bool { return in_array($s, $this->scopes, true); }
}
```

Return your type from the claims-mapper:

```php
new JwtAuthenticator(
    $jwt,
    claimsMapper: static fn(Plain $t) => new User(
        id: (string) $t->claims()->get('sub'),
        email: (string) $t->claims()->get('email'),
        tenantId: (string) $t->claims()->get('tenant'),
        scopes: explode(' ', (string) $t->claims()->get('scope', '')),
    ),
);
```

Now handlers can type-hint your concrete class on `#[FromPrincipal]`
parameters:

```php
public function __invoke(#[FromPrincipal] User $me): JsonResponse
{
    return JsonResponse::ok(['email' => $me->email, 'tenant' => $me->tenantId]);
}
```

## WebSocket auth

A WebSocket upgrade is an HTTP request, so the same
`AuthenticationMiddleware` that protects HTTP routes protects the
upgrade. Decorate the handler with `#[RequiresAuth]` (or scope/role
attributes) and add `AuthorizationMiddleware` per-route:

```php
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;

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

$app->ws('/ws/private', PrivateChatHandler::class)
    ->middleware(AuthorizationMiddleware::class);
```

The Principal is captured at upgrade time and lives for the duration of
the connection — there is no per-message reauthentication. If you need
revocation semantics, do them at the application layer (track session
ids, kill the connection when revoked).

Channel-mode (`$app->channel(...)`) routes work the same way at upgrade
time, but the actor's `onMessage()` runs in a shared worker context — the
Principal travels via the `WebSocketContext`'s upgrade request, not in
constructor args.

## Why per-route AuthorizationMiddleware?

`AuthorizationMiddleware` reads class attributes off the matched handler
class. To know which handler matched, it needs the
`_resolvedHandlerClass` request attribute — which `RouterMiddleware` sets
during route matching.

In PSR-15, global middleware runs *before* `RouterMiddleware` (the
router has to live at the bottom of the pipeline so it can dispatch into
the matched handler last). If you registered `AuthorizationMiddleware`
globally, it would run before the router and see no
`_resolvedHandlerClass`, so it would skip every authorization decision —
silently letting every request through. That's the worst possible
failure mode for an auth library, so the package enforces the per-route
pattern by being useless when registered globally.

The pattern is:

```php
$app = HttpApplication::create($system)
    ->middleware(new AuthenticationMiddleware($auth));      // global

$app->get('/me',     MeHandler::class)->middleware(AuthorizationMiddleware::class);
$app->post('/orders', CreateOrderHandler::class)->middleware(AuthorizationMiddleware::class);
$app->get('/orders/{id}', ShowOrderHandler::class)->middleware(AuthorizationMiddleware::class);
```

If you find yourself writing `->middleware(AuthorizationMiddleware::class)`
on every route, factor it into a builder helper or wrap the route
definitions in a small DSL:

```php
function protected(HttpApplication $app, string $method, string $path, string $handler): Route
{
    return $app->$method($path, $handler)->middleware(AuthorizationMiddleware::class);
}

protected($app, 'get',  '/me', MeHandler::class);
protected($app, 'post', '/orders', CreateOrderHandler::class);
```

Either way, the actual enforcement only runs after route matching, where
the handler class is known and the metadata cache can do its job.

For the full API reference — every attribute, every authenticator,
every extractor — see [nexus-http-auth](../packages/http-auth.md).
