---
sidebar_position: 8
title: nexus-http-auth
---

# nexus-http-auth

Pluggable authentication and authorization for the Nexus HTTP stack. Ships
contract interfaces (`Principal` / `Authenticator` / `Authorizer` /
`TokenExtractor`), a JWT authenticator built on lcobucci/jwt ^5, two
middleware (`AuthenticationMiddleware` global, `AuthorizationMiddleware`
per-route), seven attributes for declarative route protection, and a
default `SimplePrincipal` value object.

**Composer:** `nexus-actors/http-auth`

**Namespace:** `Monadial\Nexus\Http\Auth\`

## Quick Start

```php
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Authenticator\JwtAuthenticator;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
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
);

#[RequiresScope('orders:read')]
final class MeHandler
{
    public function __invoke(#[FromPrincipal] Principal $me): JsonResponse
    {
        return JsonResponse::ok(['id' => $me->id(), 'scopes' => $me->scopes()]);
    }
}

$app = HttpApplication::create($system)
    ->middleware(new AuthenticationMiddleware($auth));        // global

$app->get('/me', MeHandler::class)
    ->middleware(AuthorizationMiddleware::class);             // per-route
```

## Principal

The `Principal` interface is the "who" of an authenticated request.
`AuthenticationMiddleware` stamps it onto the PSR-7 request attribute
`principal`; handlers and middleware read it back.

```php
interface Principal
{
    public function id(): string;            // stable identifier for logging/audit
    public function roles(): array;          // list<string>
    public function scopes(): array;         // list<string>
    public function claims(): array;         // array<string, mixed>
    public function hasRole(string $role): bool;
    public function hasScope(string $scope): bool;
}
```

### SimplePrincipal

A readonly value object that covers most cases:

```php
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;

$me = new SimplePrincipal(
    id: 'user-42',
    roles: ['admin'],
    scopes: ['orders:read', 'orders:write'],
    claims: ['tenant' => 'acme'],
);
```

Implement `Principal` directly when you need to carry a domain entity
(e.g. a `User` aggregate) rather than just a bag of claims.

## Authenticators

The `Authenticator` interface returns a `Principal` on success or `null`
when the request is anonymous (no token, bad token, expired token). It
never throws for client errors — exceptions indicate a configuration
problem, not an unauthenticated request.

```php
interface Authenticator
{
    public function authenticate(ServerRequestInterface $request): ?Principal;
}
```

Three implementations ship.

### JwtAuthenticator

Verifies a JWT using lcobucci/jwt ^5 (HS256/RS256/ES256/EdDSA per the
configured signer) and delegates Principal construction to a
claims-mapper closure.

```php
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Monadial\Nexus\Http\Auth\Authenticator\JwtAuthenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;

$jwt = Configuration::forSymmetricSigner(
    new Sha256(),
    InMemory::plainText($secret),
);

$auth = new JwtAuthenticator(
    jwt: $jwt,
    extractor: new BearerTokenExtractor(),
    claimsMapper: static fn(Plain $t) => new SimplePrincipal(
        id: (string) $t->claims()->get('sub'),
        scopes: explode(' ', (string) $t->claims()->get('scope', '')),
    ),
    logger: $logger,
    clock: $clock,
);
```

Failure modes (bad signature, expired, malformed, wrong issuer/audience)
all return `null`. The reason is logged via PSR-3 at `debug` (malformed,
no token) or `info` (constraint violations) — never disclosed on the
wire.

### StaticTokenAuthenticator

Useful for tests and local fixtures. Compares the extracted token to a
preconfigured map.

```php
use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;

$auth = new StaticTokenAuthenticator([
    'alice-token' => new SimplePrincipal('alice', scopes: ['orders:read']),
    'bob-token'   => new SimplePrincipal('bob',   roles: ['admin']),
]);
```

### ChainAuthenticator

Tries each authenticator in order; returns the first non-null Principal.

```php
use Monadial\Nexus\Http\Auth\Authenticator\ChainAuthenticator;

$auth = new ChainAuthenticator([
    $jwtAuth,
    $apiKeyAuth,
    $sessionCookieAuth,
]);
```

## Token Extractors

All extractors implement `TokenExtractor::extract(ServerRequestInterface): ?string`.

```php
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Extractor\HeaderTokenExtractor;
use Monadial\Nexus\Http\Auth\Extractor\CookieTokenExtractor;

new BearerTokenExtractor();              // Authorization: Bearer <token>
new HeaderTokenExtractor('X-API-Key');   // X-API-Key: <token>
new CookieTokenExtractor('session');     // Cookie: session=<token>
```

Pass any of them to `JwtAuthenticator` (or your own authenticator) as the
`$extractor` argument.

## Middleware

### AuthenticationMiddleware (register globally)

```php
$app->middleware(new AuthenticationMiddleware($authenticator, $logger));
```

Runs the configured authenticator and stamps the resulting `Principal`
on the `principal` request attribute. **Never 401s** — anonymous
requests flow through unchanged. Authorization is `AuthorizationMiddleware`'s
job.

Logs at PSR-3 `debug`:
- `auth.principal.stamped` with `principalId` when authentication succeeds.
- `auth.anonymous` when no Principal is produced.

### AuthorizationMiddleware (register per-route)

**This middleware must be registered per-route, not globally.** It depends
on the `_resolvedHandlerClass` request attribute that `RouterMiddleware`
sets during route matching; global middleware runs *before*
`RouterMiddleware` in the PSR-15 pipeline, so a global registration would
silently skip enforcement.

The correct pattern:

```php
$app = HttpApplication::create($system)
    ->middleware(new AuthenticationMiddleware($auth));     // global

$app->get('/me', MeHandler::class)
    ->middleware(AuthorizationMiddleware::class);          // per-route

$app->post('/orders', CreateOrderHandler::class)
    ->middleware(AuthorizationMiddleware::class);          // per-route
```

The middleware reads class attributes off the matched handler and decides:

- `401 Unauthorized` if the route requires a Principal but none is
  stamped (with `WWW-Authenticate: Bearer` if the handler / authenticator
  exposes a challenge).
- `403 Forbidden` if the Principal lacks the required scope, role, or
  fails a custom policy.
- Pass through otherwise.

Reflection is cached: the metadata is computed once per handler class on
first access, then served from an in-memory map. Each authorization
decision is logged at PSR-3 `info`.

## Attributes

Seven attributes drive declarative protection. Six are class-level
(applied to the handler class); one is parameter-level (applied to a
`__invoke()` parameter).

| Attribute | Where | Effect |
|---|---|---|
| `#[RequiresAuth]` | class | 401 if no Principal |
| `#[RequiresScope('s1', 's2')]` | class | 403 if missing ANY (all-of) |
| `#[RequiresAnyScope('s1', 's2')]` | class | 403 if missing ALL (any-of) |
| `#[RequiresRole('r1')]` | class | 403 if missing role (all-of for multiple) |
| `#[RequiresAnyRole('r1', 'r2')]` | class | 403 if missing all listed roles |
| `#[Authorize(P::class)]` | class | Delegates to `Authorizer`; 403 if false |
| `#[FromPrincipal]` | `__invoke` param | Injects Principal from request attribute |

### #[RequiresAuth]

```php
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;

#[RequiresAuth]
final class MeHandler
{
    public function __invoke(#[FromPrincipal] Principal $me): JsonResponse
    {
        return JsonResponse::ok(['id' => $me->id()]);
    }
}
```

### #[RequiresScope] / #[RequiresAnyScope]

`RequiresScope` is **all-of**: the Principal must have every listed scope.
`RequiresAnyScope` is **any-of**: at least one suffices.

```php
#[RequiresScope('orders:read', 'orders:write')]   // both required
final class WriteHandler { /* ... */ }

#[RequiresAnyScope('orders:read', 'orders:admin')] // either suffices
final class ReadHandler { /* ... */ }
```

### #[RequiresRole] / #[RequiresAnyRole]

Mirror the scope attributes but check `Principal::hasRole()`.

```php
#[RequiresRole('admin')]                       // must be admin
#[RequiresAnyRole('admin', 'support')]         // admin OR support
```

### #[Authorize]

Delegates to a custom `Authorizer` for policy decisions you can't express
with scopes or roles:

```php
interface Authorizer
{
    public function authorize(
        Principal $principal,
        ServerRequestInterface $request,
    ): bool;
}
```

```php
use Monadial\Nexus\Http\Auth\Attribute\Authorize;
use Monadial\Nexus\Http\Auth\Authorizer;

final class OwnerOnlyPolicy implements Authorizer
{
    public function authorize(Principal $principal, ServerRequestInterface $req): bool
    {
        return $principal->id() === (string) $req->getAttribute('userId');
    }
}

#[Authorize(OwnerOnlyPolicy::class)]
final class UpdateProfileHandler { /* ... */ }
```

The Authorizer class is resolved through the PSR-11 container configured
on the application (`$app->withContainer(...)`). When the policy returns
`false`, the framework throws `Forbidden` with an empty `missing[]` —
you don't need to enumerate which scope failed when the failure is
policy-driven.

### #[FromPrincipal]

Constructor or `__invoke()` parameter injection of the current Principal:

```php
public function __invoke(#[FromPrincipal] Principal $me): JsonResponse
{
    return JsonResponse::ok(['id' => $me->id()]);
}
```

Use it on `__invoke()` parameters rather than the constructor — handler
instances are constructed once at boot, but Principal is per-request.

## WebSocket auth

A WebSocket upgrade is an HTTP request. `AuthenticationMiddleware` runs
on the upgrade exactly as for HTTP, and the Principal is available
via the upgrade request:

```php
$ctx->request()->getAttribute('principal');
```

`WebSocketHandler` constructors can use `#[FromPrincipal]` directly — the
`HandlerInstantiator` in `nexus-http-ws` (patched in T10) recognises the
attribute via FQCN string lookup so the package still works without
requiring `nexus-http-auth` as a hard dependency.

## Failure mode reference

| Trigger | Outcome | Where logged |
|---|---|---|
| No token in request | Anonymous (null Principal) | `debug` |
| Malformed token | Anonymous | `debug` |
| Bad signature | Anonymous | `info` |
| Expired token | Anonymous | `debug` |
| Wrong issuer / audience | Anonymous | `info` |
| Authenticator throws | 500 | (config error) |
| `RequiresAuth` + no Principal | 401 with `WWW-Authenticate` | `info` |
| `RequiresScope` unsatisfied | 403 with `missing[]` | `info` |
| `Authorize` policy returns false | 403 with empty `missing[]` | `info` |

## onException remap

Customise the response shape by mapping the package exceptions through
`$app->onException(...)`:

```php
use Monadial\Nexus\Http\Auth\Exception\Forbidden;
use Monadial\Nexus\Http\Auth\Exception\Unauthenticated;

$app->onException(Unauthenticated::class, static fn($e) => JsonResponse::ok([
    'error' => 'login_required',
])->withStatus(401))
    ->onException(Forbidden::class, static fn($e) => JsonResponse::ok([
        'error'   => 'forbidden',
        'missing' => $e->missing(),
    ])->withStatus(403));
```

The default responses already include sensible JSON bodies and the
`WWW-Authenticate` header where appropriate — override only when your
API contract requires it.
