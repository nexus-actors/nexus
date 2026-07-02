---
sidebar_position: 4
title: HTTP Integration
related:
  - doctrine/overview
  - doctrine/connection-pool
  - doctrine/entity-manager-pool
  - http/handlers
---

# HTTP Integration

Handlers declare a `Doctrine\DBAL\Connection` or `Doctrine\ORM\EntityManagerInterface` parameter. The framework borrows from the pool on first use and releases on response — no `try/finally` in handler code.

## Wire-up

Two facade calls — one for DBAL, one for ORM. Both return an updated `ParamResolverRegistry` (it is immutable) and append middleware to your list.

```php title="src/Bootstrap/HttpBootstrap.php"
use Monadial\Nexus\Doctrine\Dbal\Http\DoctrineHttp;
use Monadial\Nexus\Doctrine\Orm\Http\DoctrineOrmHttp;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;

$registry = new ParamResolverRegistry();
$middlewares = [];

// DBAL — pure Connection injection
$registry = DoctrineHttp::install(
    registry: $registry,
    middlewares: $middlewares,
    connPool: $dbalPool,
);

// ORM — adds EntityManagerInterface injection on top
$registry = DoctrineOrmHttp::installOrm(
    registry: $registry,
    middlewares: $middlewares,
    emPool: $emPool,
);
```

You can install either independently. The internal connection pool inside `EntityManagerPool` is separate from the DBAL `ConnectionPool` you may also expose for direct SQL handlers — they do not share state.

## Handler injection

```php title="src/Http/Handler/GetOrderById.php"
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GetOrderById
{
    public function __invoke(
        ServerRequestInterface $req,
        Connection $conn,
        EntityManagerInterface $em,
    ): ResponseInterface {
        $row = $conn->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$req->getAttribute('id')]);
        $order = $em->find(Order::class, $req->getAttribute('id'));

        return new JsonResponse([]);
    }
}
```

Zero allocation if the handler does not touch the database. Both are borrowed **lazily** on first `->get()` call inside the lease — handlers that return a 404 in a request-validation middleware before reaching the database incur no pool activity.

## Per-request leases

Two middlewares attach lease objects to the `ServerRequest` attributes:

- `ConnectionScopeMiddleware` → `ConnectionLease` attribute
- `EntityManagerScopeMiddleware` → `EntityManagerLease` attribute

Each lease has a single `get()` method that memoizes the borrow. On response (or exception), the middleware's `finally` block releases.

The DBAL lease additionally has `poison()` — called by `ConnectionScopeMiddleware` when the handler throws a `Doctrine\DBAL\Exception` (the documented root for connection-state-corrupting errors). The connection is destroyed rather than returned to the pool. Other throwables (domain, validation, 404) propagate but release the connection intact, so a single buggy handler cannot shrink the pool.

The EM lease has no poison flag — the pool handles EM-closed state on its own via the `release()` resolution order.

## `#[Transactional]`

A class-level or method-level attribute that wraps the handler in a transaction. Picks the right wrapping based on what the handler declares.

### DBAL path (Connection only)

```php title="src/Http/Handler/CreateOrder.php"
use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;

#[Transactional]
final class CreateOrder
{
    public function __invoke(
        ServerRequestInterface $req,
        Connection $conn,
    ): ResponseInterface {
        $conn->insert('orders', [...]);
        // automatic commit on success, rollBack on throw
        return new JsonResponse(['id' => $conn->lastInsertId()]);
    }
}
```

`TransactionalDecorator` reads the `ConnectionLease` from the request, calls `$conn->beginTransaction()` before delegating to the inner handler, then `commit()` on success or `rollBack()` on throw.

### ORM path (EntityManagerInterface)

```php title="src/Http/Handler/UpdateOrder.php"
use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;

#[Transactional]
final class UpdateOrder
{
    public function __invoke(
        ServerRequestInterface $req,
        EntityManagerInterface $em,
    ): ResponseInterface {
        $order = new Order(...);
        $em->persist($order);
        // wrapInTransaction handles flush + commit
        return new JsonResponse(['id' => $order->getId()]);
    }
}
```

`TransactionalEmDecorator` calls `$em->wrapInTransaction(fn() => $this->inner->handle($req))`. Doctrine handles the begin/flush/commit/rollback semantics.

### Picking the decorator

You decide at wiring time which decorator to apply per route. The Psalm rule `MissingTransactionalDeclarationRule` warns at build-time when a handler has `#[Transactional]` but declares neither `Connection` nor `EntityManagerInterface` — without one of those parameters the attribute silently no-ops.

## Pool exhaustion → 503

`PoolExhaustedException` bubbles up to `PoolExhaustedToServiceUnavailable` middleware (auto-installed by `DoctrineHttp::install()`), which converts it to:

```
HTTP/1.1 503 Service Unavailable
Retry-After: 1
```

Other database errors propagate to your application's existing error handler. The default `retryAfterSeconds` is `1`; pass a different value to the constructor if you want a longer cooldown.

## Resolvers

Two `ParamResolver` implementations integrate with the existing handler resolver registry (see [HTTP / Handlers](../http/handlers.md)):

- `ConnectionResolver` — matches parameter type `Doctrine\DBAL\Connection`
- `EntityManagerResolver` — matches parameter type `Doctrine\ORM\EntityManagerInterface`

Both gate on `RequestBoundContext` (the abstract parent of `HttpRequestContext`), so they also work for WebSocket connection contexts if you wire database access into a WebSocket lifecycle hook.

Throws `MissingConnectionScopeException` or `MissingEntityManagerScopeException` if the corresponding middleware is not installed — fail-fast with a clear message.

## Error mapping summary

| Scenario | Response | Notes |
|---|---|---|
| Pool exhausted | 503 + `Retry-After: 1` | via `PoolExhaustedToServiceUnavailable` |
| Connection lost mid-handler | 500 (default error handler) | connection is poisoned; pool builds a fresh one |
| SQL error (constraint violation, etc.) | 500 (default error handler) | connection is NOT poisoned — SQL errors are caller bugs |
| `#[Transactional]` + throw | 500 + `rollBack()` happened | propagates after rollback |
| Middleware not installed | 500 + `MissingConnectionScopeException` | fail-fast, clear message |

## See also

- [Connection Pool](./connection-pool.md) — pool configuration and borrow semantics
- [EntityManager Pool](./entity-manager-pool.md) — EM pool, `clearOnReturn`, and eviction policy
- [Doctrine Overview](./overview.md) — choosing between `Connection` and `EntityManagerInterface` injection
