---
title: AuthenticationMiddleware
sidebar_position: 22
related:
  - http/auth
  - reference/classes/requires-auth
---

# AuthenticationMiddleware

PSR-15 middleware that authenticates every inbound request and stamps the resulting
`Principal` onto the request attribute bag.

## What it does

`AuthenticationMiddleware` wraps an `Authenticator` strategy and runs on every
request in the middleware pipeline. Its responsibilities are narrow and deliberate:

1. **Authenticate** — calls `Authenticator::authenticate($request)`. The authenticator
   inspects headers, cookies, or tokens and returns a `Principal` on success or `null`
   for anonymous requests.
2. **Stamp the principal** — if authentication succeeds, writes the `Principal` to the
   `'principal'` request attribute for downstream handlers to read.
3. **Set the checked flag** — sets `AuthenticationMiddleware::CHECKED_ATTRIBUTE`
   (`'nexus.auth.checked'`) to `true` on every request, regardless of outcome.

The checked flag lets downstream resolvers distinguish two failure modes: a `null`
principal with `checked = true` means "middleware ran but no credentials were
supplied" (should 401), while `checked` absent entirely means "the middleware was
never registered" (likely a 500-level configuration error).

`AuthenticationMiddleware` **never produces a 401 or 403 response** — anonymous
requests pass through unchanged. Access control is enforced downstream by
`AuthorizationMiddleware`, which reads route-level PHP attributes such as
`#[RequiresAuth]`, `#[RequiresRole]`, and `#[RequiresScope]`.

## Example

```php title="src/Bootstrap/HttpKernel.php"
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use App\Auth\JwtAuthenticator;

$authenticator = new JwtAuthenticator($jwtParser, $userRepository);

$app = HttpApp::create()
    ->middleware(new AuthenticationMiddleware($authenticator, $logger))
    ->get('/health', static fn() => Response::ok())       // public — no attribute
    ->get('/orders', OrderListHandler::class)              // #[RequiresAuth] on class
    ->post('/admin/users', CreateUserHandler::class);      // #[RequiresRole('admin')]
```

## Key methods

- `__construct(Authenticator $authenticator, LoggerInterface $logger = new NullLogger())`
  — wraps any `Authenticator` implementation; logger is optional.
- `process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface`
  — PSR-15 entry point; sets the checked flag, stamps the principal if present, and
  delegates to the next handler.

## Constants

- `CHECKED_ATTRIBUTE = 'nexus.auth.checked'` — boolean request attribute set on every
  request that passes through this middleware.

## Full API reference

[Full class and method signatures](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Auth-Middleware-AuthenticationMiddleware.html)

## See also

- [HTTP auth guide](../../http/auth) — authenticators, principals, and the full
  auth/authz pipeline
- [RequiresAuth](requires-auth) — route-level attribute that enforces authentication
  downstream of this middleware
