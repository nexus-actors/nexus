---
sidebar_position: 8
title: nexus-http-auth
related:
  - packages/http
  - packages/http-ws
  - http/handlers
---

# nexus-http-auth

Pluggable authentication and authorization for the Nexus HTTP stack, including a JWT authenticator, declarative route-protection attributes, and `#[FromPrincipal]` parameter injection.

## What's in this package

- `Principal` interface + `SimplePrincipal` value object — the "who" of an authenticated request
- `JwtAuthenticator` — verifies JWTs via lcobucci/jwt ^5 (HS256/RS256/ES256/EdDSA)
- `StaticTokenAuthenticator`, `ChainAuthenticator` — test and multi-scheme alternatives
- `BearerTokenExtractor`, `HeaderTokenExtractor`, `CookieTokenExtractor` — token sources
- `AuthenticationMiddleware` — stamps `Principal` on the request; register globally
- `AuthorizationMiddleware` — enforces attributes; register per-route
- `#[RequiresAuth]`, `#[RequiresScope]`, `#[RequiresAnyScope]`, `#[RequiresRole]`, `#[RequiresAnyRole]`, `#[Authorize]` — class-level protection attributes
- `#[FromPrincipal]` — `__invoke()` parameter injection of the current principal
- `FromPrincipalResolver` — register once to enable `#[FromPrincipal]`

## Install

```bash
composer require nexus-actors/http-auth
```

## Quick example

```php title="src/Http/Handler/MeHandler.php"
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Monadial\Nexus\Http\Auth\Authenticator\JwtAuthenticator;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Auth\Resolver\FromPrincipalResolver;
use Monadial\Nexus\Http\Response\JsonResponse;

$jwt  = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(getenv('JWT_SECRET')));
$auth = new JwtAuthenticator($jwt, claimsMapper: static fn(Plain $t) => new SimplePrincipal(
    id: (string) $t->claims()->get('sub'),
    scopes: explode(' ', (string) $t->claims()->get('scope', '')),
));

#[RequiresScope('orders:read')]
final class MeHandler
{
    public function __invoke(#[FromPrincipal] Principal $me): JsonResponse
    {
        return JsonResponse::ok(['id' => $me->id()]);
    }
}

$app = HttpApplication::create($system)
    ->middleware(new AuthenticationMiddleware($auth))
    ->paramResolver(new FromPrincipalResolver());

$app->get('/me', MeHandler::class)->middleware(AuthorizationMiddleware::class);
```

`AuthorizationMiddleware` must be registered per-route, not globally. It depends on the `_resolvedHandlerClass` request attribute that `RouterMiddleware` sets during route matching.

## See also

- [nexus-http](./http.md) — core HTTP primitives and param resolver API
- [nexus-http-ws](./http-ws.md) — WebSocket auth uses the same middleware and resolver
- [HTTP / handlers](../http/handlers.md) — handler DI and param resolver guide
