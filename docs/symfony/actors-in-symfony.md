# Actors in Symfony services

## Overview

Nexus provides two mechanisms for using actors from within a Symfony application:

1. **Isolated actors** — declared with `#[Actor(ActorType::Isolated, 'name')]`, spawned once per worker at startup, accessible from any service via autowiring.
2. **Worker-start bootstrappers** — services implementing `WorkerStartBootstrapper`, called once per worker before any requests are served.

## Declaring an isolated actor

An isolated actor is a class implementing `ActorHandler` (or extending `AbstractActor`) annotated with `#[Actor]`:

```php
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;

#[Actor(ActorType::Isolated, 'catalog')]
final class CatalogActor implements ActorHandler
{
    public function __construct(private readonly CacheInterface $cache) {}

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof GetProducts) {
            $ctx->reply(new ProductList($this->loadProducts()));
            return Behavior::same();
        }

        return Behavior::unhandled();
    }
}
```

The `name` argument (`'catalog'` above) determines the actor's path within the system and the service ID used for injection.

### How it works at compile time

`ActorRegistrationPass` (a Symfony compiler pass) scans all container definitions for the `#[Actor]` attribute. For each `Isolated` actor it:

1. Registers an `ActorPropsFactory` service at `nexus.actor.{name}.props_factory`.
2. Registers a synthetic `ActorRef` definition at `nexus.actor_ref.{name}`.
3. Stores the name → props_factory mapping in the `nexus.isolated_actors` container parameter.

At runtime, `NexusRunner` reads `nexus.isolated_actors`, calls each `ActorPropsFactory::create()`, spawns the actor into the worker's `ActorSystem`, and sets the live `ActorRef` onto the container at `nexus.actor_ref.{name}`.

## Injecting an ActorRef into a service

Use `#[Autowire(service: 'nexus.actor_ref.name')]` to inject the actor reference:

```php
use Monadial\Nexus\Core\Actor\ActorRef;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CatalogController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'nexus.actor_ref.catalog')]
        private readonly ActorRef $catalogActor,
    ) {}
}
```

The injected `ActorRef` is valid for the lifetime of the worker. Because each worker has its own actor system, each worker injects its own local `ActorRef` — there is no cross-worker ref sharing for isolated actors.

## Actor dependencies

The `ActorPropsFactory` uses `Props::fromContainer($container, $class)` internally. This means the actor class is resolved from the Symfony container, so all its constructor dependencies are autowired normally. Services available in the kernel container — Doctrine repositories, cache, Messenger transports, and so on — are all accessible.

## ask() vs tell() from controllers

### tell() — fire and forget

Use `tell()` when the controller does not need the result:

```php
#[Route('/orders', methods: ['POST'])]
public function create(Request $request): JsonResponse
{
    $command = new PlaceOrder($request->request->all());
    $this->orderActor->tell($command);

    return new JsonResponse(['status' => 'accepted'], 202);
}
```

`tell()` is non-blocking and returns immediately. The actor processes the message asynchronously.

### ask() — request-response via Future

Use `ask()` when the response is needed to build the HTTP reply. `ask()` returns a `Future<T>` that suspends the current Swoole coroutine until the actor replies (or the timeout elapses):

```php
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;

/** @return Future<JsonResponse> */
#[Route('/catalog', methods: ['GET'])]
public function list(): Future
{
    return $this->catalogActor
        ->ask(new GetProducts(), Duration::seconds(5))
        ->map(static fn(ProductList $list) => new JsonResponse(
            array_map(static fn(Product $p) => $p->toArray(), $list->items),
        ));
}
```

### Returning a Future from a controller

`NexusBundle` registers a `FutureResponseListener` on the `kernel.view` event (priority 100). When a controller returns a `Future`, this listener calls `$future->await()` to resolve it to a concrete response before Symfony's response handling continues. This keeps the controller code non-blocking (the coroutine suspends at `await()`, allowing other coroutines to run) while remaining compatible with standard Symfony routing and response handling.

### Fan-out: multiple concurrent asks

Multiple `ask()` calls are issued before any `await()` call so they execute concurrently within the same coroutine:

```php
/** @return Future<JsonResponse> */
public function list(RequestContext $ctx): Future
{
    // Both futures are in-flight simultaneously — no sequential blocking.
    $productsFuture = $this->catalogActor->ask(new GetProducts(), Duration::seconds(5));
    $stockFuture    = $this->inventoryActor->ask(new GetStock($ids), Duration::seconds(5));

    return $productsFuture->map(
        static function (ProductList $list) use ($stockFuture): JsonResponse {
            $stock = $stockFuture->await();  // suspends only if not yet resolved

            return new JsonResponse([
                'products' => array_map(
                    static fn(Product $p) => [...$p->toArray(), 'stock' => $stock->levels[$p->id] ?? 0],
                    $list->items,
                ),
            ]);
        },
    );
}
```

## WorkerStartBootstrapper — per-worker initialization

Services implementing `WorkerStartBootstrapper` are called once per Swoole worker during the `workerStart` event, before any HTTP requests are served. This is the place to initialize worker-local resources such as connection pools.

```php
use Monadial\Nexus\Symfony\Runtime\WorkerStartBootstrapper;
use Psr\Container\ContainerInterface;

final class DatabasePoolBootstrapper implements WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        // Initialize a Swoole connection pool for this worker.
        // The pool is stored in a static or coroutine-context-aware location.
        SwooleDbPool::initialize(size: 32);
    }
}
```

Services implementing this interface are discovered automatically via Symfony autoconfiguration (the bundle registers `WorkerStartBootstrapper` for the `nexus.worker_start` tag). Alternatively, tag explicitly:

```yaml
# config/services.yaml
App\Infrastructure\DatabasePoolBootstrapper:
    tags:
        - { name: nexus.worker_start }
```

`onWorkerStart()` receives the booted container and the zero-based worker ID. The container is the same instance available to actors and services for that worker.

## Accessing ActorSystem and Runtime directly

Both services are available for injection by type:

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Runtime\Runtime;

final class MyService
{
    public function __construct(
        private readonly ActorSystem $actorSystem,
        private readonly Runtime $runtime,
    ) {}
}
```

These are synthetic services set by `NexusRunner` on each worker's container after booting. They must not be accessed during container compilation or in `WorkerStartBootstrapper::onWorkerStart()` before the actor system has been created — the creation order within `NexusRunner::run()` is: bootstrappers → set actor_system/runtime → boot isolated actors → spawn kernel pool → start runtime.

## Actor messages

Messages passed to `tell()` and `ask()` should be `readonly` classes. The Nexus Psalm plugin enforces this when static analysis is enabled:

```php
readonly class GetProducts {}

readonly class ProductList
{
    /** @param Product[] $items */
    public function __construct(public array $items) {}
}
```
