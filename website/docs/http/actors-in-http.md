---
sidebar_position: 9
title: Actors in HTTP
---

# Actors in HTTP

The whole point of Nexus HTTP is to put actors next to your routes
without the usual ceremony. This page covers the integration patterns:
where actors come from, how they're injected, how to await replies, and
how to bridge the synchronous request/response shape onto the
asynchronous actor model.

## Registering Actors

Two scopes:

```php
// Singleton — one instance per worker (or thread)
$app->actor('orders', Props::fromFactory(fn() => new OrderActor($store)));

// Per-request — fresh instance per HTTP request, stopped at response time
$app->perRequestActor('audit', Props::fromFactory(fn() => new AuditBufferActor()));
```

Singletons are spawned at boot, ready to serve requests immediately.
Per-request actors are spawned on first injection inside a request, and
stopped after the response is written.

The choice is about state lifetime:

- **Singleton** — actor state shared across requests (an open
  connection pool, a deduplication map, a hot cache).
- **Per-request** — actor state scoped to a single HTTP turn (an audit
  trail, a unit-of-work transaction, request-scoped query log).

## Injection

Inject the `ActorRef` directly into your handler:

```php
final class ShowOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}

    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $id = (string) $req->getAttribute('id');
        $this->orders->tell(new IncrementViewCount($id));

        // …
    }
}
```

`#[FromActor('name')]` matches a registered actor name. The framework
resolves it at request time:

- **Singleton** → the cached `ActorRef`, allocated once at boot.
- **Per-request** → a freshly spawned actor; auto-stopped via
  `PerRequestActorScope::dispose()` in the router's `finally` block.

The two are interchangeable from the handler's perspective — the type is
just `ActorRef` either way.

## Tell vs Ask

Two send patterns, with different semantics:

### Tell — fire-and-forget

```php
$this->orders->tell(new PlaceOrder($dto));
return Response::created();
```

The handler returns immediately. The message lands in the actor's
mailbox; the actor processes it on its next turn. There is **no reply**
and **no error path** — exceptions inside the actor are caught by
supervision, not by the handler.

Use `tell` for:

- Write commands where the response is just "accepted, processing"
- Event publishing (subscribers process asynchronously)
- Audit / instrumentation messages
- Anything where the actor is the source of truth and the HTTP response
  shouldn't wait

### Ask — request-response with timeout

```php
$order = $this->orders
    ->ask(new GetOrder($id), Duration::seconds(2))
    ->await();

return JsonResponse::ok($order->toArray());
```

`ask()` returns a `Future` immediately; `await()` **suspends the
fiber/coroutine** until the actor sends a reply or the timeout expires.
Other requests on the same thread keep running — this isn't a
system-thread block, it's a cooperative await.

The framework attaches an ephemeral reply ref to the envelope as the
sender. The actor reads it via `$ctx->sender()->tell($reply)` and the
framework resumes the waiting fiber with the typed result.

### Choosing Between Them

| Path | Tell or ask |
|---|---|
| Reads where the actor holds the data | `ask` |
| Reads where the actor is a cache and a miss should fall through | `ask`, with a fallback in the timeout handler |
| Writes where the client must wait for persistence to confirm | `ask` (handler returns `201`/`202` after reply) |
| Writes where the client doesn't need confirmation | `tell` (handler returns `202` immediately) |
| Fire-and-forget side effects (audit, metrics) | `tell` |

## Handling Ask Timeouts

`ask` raises `AskTimeoutException` if no reply arrives. Map it to a
gateway timeout in your error mapper:

```php
$app->onException(AskTimeoutException::class, static fn() => Response::gatewayTimeout());
```

Or catch it locally if you have a fallback:

```php
try {
    $cached = $this->cache->ask(new Get($key), Duration::millis(50))->await();
    return JsonResponse::ok($cached->value);
} catch (AskTimeoutException) {
    $fresh = $this->repo->fetch($key);
    $this->cache->tell(new Set($key, $fresh));
    return JsonResponse::ok($fresh);
}
```

Pick the timeout per call. There's no global default — be explicit.

## Future-Returning Handlers

The router supports handlers that return `Future<ResponseInterface>`
directly. The dispatcher awaits the future before serialising; the
handler doesn't have to block on `await`:

```php
use Monadial\Nexus\Runtime\Async\Future;

public function __invoke(ServerRequestInterface $req): Future
{
    $id = (string) $req->getAttribute('id');

    return $this->orders
        ->ask(new GetOrder($id), Duration::seconds(2))
        ->map(static fn(Order $order) => JsonResponse::ok($order->toArray()));
}
```

`ask()` already returns a `Future` — `map()` chains the transformation
without ever blocking. This is useful when you want to compose multiple
async steps via `Future::map` / `Future::flatMap` without nested
`try`/`catch`. The router awaits whatever you return: `ResponseInterface`,
`Future`, or a `Future` that resolves to a `ResponseInterface`.

### Combining Futures

```php
public function __invoke(ServerRequestInterface $req): Future
{
    $userId = (string) $req->getAttribute('id');

    return Future::all([
        'user'   => $this->users->ask(new GetUser($userId), Duration::seconds(2)),
        'orders' => $this->orders->ask(new ListByUser($userId), Duration::seconds(2)),
    ])->map(static fn(array $parts) => JsonResponse::ok([
        'user'   => $parts['user']->toArray(),
        'orders' => array_map(fn($o) => $o->toArray(), $parts['orders']),
    ]));
}
```

`Future::all` resolves when every future resolves. If any of them rejects,
the resulting future rejects with the first error — caught by the
exception middleware like any other thrown exception.

### When to Pick await() vs Future-chaining

| Style | Pick when |
|---|---|
| `$ref->ask(...)->await()` | Simple, single-actor reads; you want try/catch semantics |
| `$ref->ask(...)->map(...)` | Composing 2+ async steps; you want the chain to read top-to-bottom |
| `Future::all([...])` | True fan-out where multiple actors can work in parallel |

The performance is identical — `await()` is just a synchronous unwrap
of the same `Future` `map()` chains on. The choice is about readability.

## Per-Request State

Per-request actors give you per-turn workspace without globals:

```php
$app->perRequestActor('uow', Props::fromFactory(fn() => new UnitOfWorkActor()));

final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('uow')] private readonly ActorRef $uow,
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}

    public function __invoke(ServerRequestInterface $req, #[FromBody] CreateOrderDto $dto): ResponseInterface
    {
        $this->uow->tell(new BeginTransaction());
        $orderId = $this->orders->ask(new Place($dto, $this->uow), Duration::seconds(2))->await();
        $this->uow->ask(new Commit(), Duration::seconds(2))->await();

        return JsonResponse::ok(['id' => $orderId]);
    }
}
```

The `uow` actor is spawned at the start of the request and stopped after
the response — `PostStop` runs cleanly, so you can drop in-flight state
to a log there.

If the handler throws, the per-request actor is **still stopped**. The
router's `finally` block guarantees disposal regardless of outcome.

## Per-Request Scope Reuse

Multiple handlers and middleware on the same request all see the **same**
per-request actor instance. The framework keys them by request, not by
injection point:

```php
$app->perRequestActor('audit', Props::fromFactory(fn() => new AuditBufferActor()));
$app->middleware(AccessLogMiddleware::class);   // also injects 'audit'

// Both AccessLogMiddleware and the handler get the same AuditBufferActor
// for one request. Different requests get different instances.
```

This is what makes per-request actors useful for cross-cutting state —
the middleware can record context, the handler can add business events,
and `PostStop` flushes the merged buffer.

## Crossing Worker Boundaries

For multi-thread deployments, `tell` and `ask` work seamlessly across
threads via the worker pool:

```php
$ordersRef = $node->lookupSingleton('orders');   // shared singleton on thread N
$ordersRef->tell(new PlaceOrder($dto));
```

The message is delivered to whichever thread owns the actor instance,
via `Swoole\Thread\Queue` — no serialization. See
[Scaling](../scaling/overview.md) for the pool-singleton pattern.

In worker-mode Swoole, every worker is isolated — actors are per-worker
and there's no cross-worker dispatch. Use thread mode if you need shared
state.

## Bridging HTTP to Existing Actors

If you already have an actor system running, expose it via HTTP:

```php
SwooleThreadServer::run($config, function ($system, $node) use ($existingOrderRef) {
    return HttpApplication::create($system)
        ->actor('orders', Props::fromBehavior(/* wrap ref */))
        ->get('/orders/{id}', ShowOrderHandler::class)
        ->compile();
});
```

For the lifetime of the worker, the HTTP route holds the actor ref and
talks to it like any other handler. There's no extra wiring — the
abstraction is already correct.

## Composition

```
HTTP request
    │
    ▼
ExceptionHandlerMiddleware
    │
    ▼
Global / group / per-route middleware
    │
    ▼
RouterMiddleware
    │
    ├── matches route → resolves handler
    │   │
    │   ├── handler injects ActorRef (#[FromActor])
    │   ├── handler injects DTO (#[FromBody])
    │   └── handler injects service (#[FromService])
    │
    ▼
Handler::__invoke()
    │
    ├── $actor->tell(...)              → fire-and-forget
    ├── $actor->ask(...)->await()      → suspend until reply
    ├── $actor->ask(...)->map(...)     → compose Futures
    │
    ▼
return ResponseInterface | Future
    │
    ▼
Per-request actor scope disposed (if any)
    │
    ▼
Response written, exceptions mapped
```

See [Servers](./servers.md) for the deployment story.
