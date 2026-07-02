---
title: RequiresAuth
sidebar_position: 23
related:
  - http/auth
  - reference/classes/authentication-middleware
---

# RequiresAuth

PHP attribute that enforces authentication on a route handler class; sibling
attributes `RequiresRole`, `RequiresAnyRole`, `RequiresScope`, and `RequiresAnyScope`
add role- and scope-based authorisation.

## What it does

Place `#[RequiresAuth]` on a handler class to declare that a valid, authenticated
`Principal` must be present on the request. `AuthorizationMiddleware` reads the
attribute at dispatch time and returns `401 Unauthorized` if no principal was stamped
by `AuthenticationMiddleware`.

The full attribute family covers four levels of access control:

| Attribute | Effect |
|---|---|
| `#[RequiresAuth]` | 401 if not authenticated |
| `#[RequiresRole('admin')]` | 401 if not authenticated; 403 if authenticated but role not present |
| `#[RequiresAnyRole('admin', 'editor')]` | 403 if authenticated but none of the listed roles match |
| `#[RequiresScope('orders:read')]` | 401 if not authenticated; 403 if scope not in token |
| `#[RequiresAnyScope('orders:read', 'orders:write')]` | 403 if authenticated but none of the listed scopes match |

All sibling attributes imply authentication — they will also produce a 401 when no
principal is present.

The attributes only take effect when `AuthenticationMiddleware` (to populate the
principal) and `AuthorizationMiddleware` (to enforce the attributes) are both
registered in the middleware pipeline.

## Example

```php title="src/Handler/OrderListHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;
use Monadial\Nexus\Http\Auth\Attribute\RequiresRole;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

// Requires only authentication — any valid principal is allowed
#[RequiresAuth]
final class OrderListHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal'); // non-null here
        // ...
    }
}

// Requires the 'admin' role — 403 for authenticated users without it
#[RequiresRole('admin')]
final class AdminDashboardHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // ...
    }
}

// Requires an OAuth scope — typical for API key / JWT-based access
#[RequiresScope('orders:write')]
final class CreateOrderHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // ...
    }
}
```

## Attribute signatures

- `#[RequiresAuth]` — no parameters; target: class.
- `#[RequiresRole(string $role)]` — exact role match.
- `#[RequiresAnyRole(string ...$roles)]` — at least one role must match.
- `#[RequiresScope(string $scope)]` — exact scope match.
- `#[RequiresAnyScope(string ...$scopes)]` — at least one scope must match.

## Full API reference

- [RequiresAuth](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Auth-Attribute-RequiresAuth.html)
- [RequiresRole](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Auth-Attribute-RequiresRole.html)
- [RequiresAnyRole](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Auth-Attribute-RequiresAnyRole.html)
- [RequiresScope](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Auth-Attribute-RequiresScope.html)
- [RequiresAnyScope](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Auth-Attribute-RequiresAnyScope.html)

## See also

- [HTTP auth guide](../../http/auth) — full pipeline: authenticators, principals,
  AuthorizationMiddleware, and custom policy objects
- [AuthenticationMiddleware](authentication-middleware) — the middleware that
  populates the `Principal` these attributes check against
