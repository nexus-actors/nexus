# nexus-http

PSR-15 HTTP framework on the Nexus actor system. Compile-time pipeline,
actor injection via attributes, three lifecycle modes, streaming responses.

## Install

```bash
composer require nexus-actors/http
```

## Quickstart

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$system = ActorSystem::create('my-app', new FiberRuntime());

$app = HttpApp::create($system)
    ->get('/hello', static fn() => JsonResponse::ok(['msg' => 'hello']));

// Hand the compiled app to a server adapter (e.g. nexus-http-server-swoole).
// compile() returns a CompiledHttpApp — an immutable, PSR-15 RequestHandlerInterface.
$server->serve($app->compile());
```

## Actor injection

```php
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Psr\Http\Message\ServerRequestInterface;

$app->actor('user-store', $userStoreProps)->workerLocal();
$app->perRequestActor('order-saga', $sagaProps);

$app->get('/users/{id}', static fn(
    ServerRequestInterface $r,
    #[FromActor('user-store')] ActorRef $store,
) => /* use $store->ask(...)->await() */);
```

## Service injection

Handlers and middleware can pull any PSR-11 container service:

```php
use Monadial\Nexus\Http\Handler\Attribute\FromService;

$app->get('/orders', static fn(
    #[FromService('order.repository')] OrderRepository $repo,
) => JsonResponse::ok($repo->listAll()));

// Or resolve by parameter type (no id needed):
$app->get('/users', static fn(
    #[FromService] UserService $users,
) => JsonResponse::ok($users->all()));
```

## Lifecycle modes

| Mode                  | Where it lives                          | Use for                                  |
|-----------------------|-----------------------------------------|------------------------------------------|
| `poolSingleton()`     | One per worker pool (hash-routed)       | Stateful aggregates, gateways            |
| `workerLocal()` (default) | One per worker thread               | Metric collectors, caches                |
| `perRequestActor()`   | Spawned per request, stopped at end     | Per-request sagas, behavior switching    |

## Async / Future handlers

`ActorRef::ask` returns a `Future<R>` directly. Sync-style handlers call
`->await()`; async-style handlers return the `Future` for the framework to
resolve before serializing the response.

```php
use Monadial\Nexus\Core\Concurrent\Future;

$app->get('/dashboard', static function (
    #[FromActor('user-store')] ActorRef $users,
    #[FromActor('orders')] ActorRef $orders,
): Future {
    return Future::all([
        'user'   => $users->ask(new GetUser(1), Duration::seconds(1)),
        'orders' => $orders->ask(new ListOrders(1), Duration::seconds(1)),
    ])->map(static fn(FutureResult $r) => JsonResponse::ok($r->toArray()));
});
```

## See also

- `docs/superpowers/specs/2026-06-10-nexus-http-core-design.md` — full design.
- Section 19 of the spec — API clarifications recorded during implementation.

## Repository

> **Read-only subtree split** of [nexus-actors/nexus](https://github.com/nexus-actors/nexus).
> Report issues and send pull requests to the monorepo — this repository only receives
> automated pushes and release tags.
