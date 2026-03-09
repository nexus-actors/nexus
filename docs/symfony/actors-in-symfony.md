# Actors in Symfony

Nexus integrates actors into Symfony applications through a small set of attributes, a compiler pass, and a per-worker bootstrapper. This page covers the complete surface area: declaring actors, wiring dependencies, communicating from controllers, defining messages, and initializing worker-local resources.

---

## Section 1: Declaring Actors

### The #[Actor] Attribute

Every actor class that participates in the Symfony integration must carry the `#[Actor]` attribute:

```php
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;

#[Actor(ActorType::Isolated, 'catalog')]
final class CatalogActor implements ActorHandler
{
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::unhandled();
    }
}
```

**Attribute reference:**

| Parameter | Type | Description |
|---|---|---|
| `type` | `ActorType` | `ActorType::Isolated` or `ActorType::Shared` |
| `name` | `string` | Unique logical name within the actor system. Used in service IDs and actor paths. |

### ActorType::Isolated vs ActorType::Shared

`ActorType::Isolated` means the actor is spawned once per Swoole worker thread at startup (via `WorkerStartBootstrapper` / `NexusRunner`). Each worker has its own private instance of the actor. No cross-worker ref sharing occurs. This is the correct type for stateful actors that own per-worker resources (caches, connection pools, in-memory indexes).

`ActorType::Shared` is declared on actors that are managed outside the per-worker spawn mechanism — for example, an actor spawned directly within a `WorkerStartBootstrapper` or managed by application logic. The `ActorRegistrationPass` does not register `Shared` actors for automatic spawn; they are ignored by the compiler pass.

> **Note:** In most Symfony applications, all top-level application actors use `ActorType::Isolated`. The `Shared` type exists for actors whose spawn lifecycle is managed entirely by application code rather than by the bundle.

### Implementing ActorHandler

The minimal interface for an actor is `ActorHandler`:

```php
interface ActorHandler
{
    public function handle(ActorContext $ctx, object $message): Behavior;
}
```

For actors that need lifecycle hooks (`onPreStart`, `onPostStop`), extend `AbstractActor` instead:

```php
use Monadial\Nexus\Core\Actor\AbstractActor;

#[Actor(ActorType::Isolated, 'order-processor')]
final class OrderProcessorActor extends AbstractActor
{
    public function onPreStart(ActorContext $ctx): void
    {
        // Runs once per worker after the actor starts.
        $ctx->scheduleRepeatedly(Duration::zero(), Duration::seconds(1), new Poll());
    }

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::unhandled();
    }

    public function onPostStop(ActorContext $ctx): void
    {
        // Cleanup resources.
    }
}
```

See [Actor Lifecycle](./actor-lifecycle.md) for the full lifecycle reference.

---

## Section 2: Creating Actors from Symfony Services

Actors declared with `#[Actor]` are standard Symfony services. Constructor dependencies are resolved by the DI container via autowiring, exactly as with any other service.

```php
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[Actor(ActorType::Isolated, 'inventory')]
final class InventoryActor extends AbstractActor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return match (true) {
            $message instanceof GetStock => $this->onGetStock($ctx, $message),
            default                      => Behavior::unhandled(),
        };
    }

    private function onGetStock(ActorContext $ctx, GetStock $message): Behavior
    {
        $levels = [];

        foreach ($message->productIds as $id) {
            $levels[$id] = $this->cache->get(
                "inventory.stock.{$id}",
                function () use ($id): int {
                    // Doctrine query — safe within a Swoole coroutine when
                    // using a coroutine-aware DBAL middleware.
                    return $this->em->getRepository(StockEntry::class)->countFor($id);
                },
            );
        }

        $ctx->reply(new StockLevel($levels));

        return Behavior::same();
    }
}
```

No special configuration is required. Standard autowiring — `EntityManagerInterface`, `CacheInterface`, `LoggerInterface` — works out of the box.

### How the compiler pass works

At compile time, `ActorRegistrationPass` scans all container definitions for the `#[Actor]` attribute. For each `Isolated` actor it performs three actions:

1. **Marks the actor service public** — so it can be retrieved by class name from the container at runtime.
2. **Registers an `ActorPropsFactory` service** at the ID `nexus.actor.{name}.props_factory`. This factory holds a reference to the container and the actor class name. When called at worker start, it calls `Props::fromContainer($container, $class)` to produce the `Props` object that carries the DI-resolved actor instance.
3. **Registers a synthetic `ActorRef` definition** at the ID `nexus.actor_ref.{name}`. This definition is a placeholder at compile time; the live `ActorRef` is injected into the container by `NexusRunner` at worker start, after the actor has been spawned.

The name-to-factory map is stored as the container parameter `nexus.isolated_actors` (`array<string, string>` mapping actor name → props_factory service ID). `NexusRunner` reads this parameter to determine which actors to spawn on each worker.

```
Compile time:
  #[Actor(Isolated, 'catalog')] on CatalogActor
    │
    ├─ container['nexus.actor.catalog.props_factory'] = ActorPropsFactory(container, CatalogActor::class)
    ├─ container['nexus.actor_ref.catalog'] = synthetic ActorRef (placeholder)
    └─ parameter['nexus.isolated_actors'] = ['catalog' => 'nexus.actor.catalog.props_factory', ...]

Worker start:
  NexusRunner::run()
    │
    ├─ reads nexus.isolated_actors
    ├─ calls ActorPropsFactory::create() → Props::fromContainer(container, CatalogActor::class)
    ├─ calls ActorSystem::spawn($props, 'catalog') → ActorRef<CatalogActor>
    └─ sets live ActorRef into container['nexus.actor_ref.catalog']
```

### Manual service configuration

If the actor requires a non-autowirable dependency or a named argument, configure it in `services.yaml` as usual:

```yaml
# config/services.yaml
App\Actor\AnalyticsActor:
    arguments:
        $transport: '@messenger.transport.analytics'
```

The `#[Actor]` attribute on the class still takes effect; the compiler pass picks up the already-defined service definition.

---

## Section 3: Injecting ActorRef into Controllers and Services

After compilation and worker start, each isolated actor's `ActorRef` is available in the container under the ID `nexus.actor_ref.{name}`. Inject it with Symfony's `#[Autowire]` attribute:

```php
use Monadial\Nexus\Core\Actor\ActorRef;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CatalogController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'nexus.actor_ref.catalog')]
        private readonly ActorRef $catalogActor,
        #[Autowire(service: 'nexus.actor_ref.inventory')]
        private readonly ActorRef $inventoryActor,
    ) {}
}
```

The naming convention is `nexus.actor_ref.{name}` where `{name}` is the value passed to `#[Actor(name: '...')]`.

The same pattern works in any service, not only controllers:

```php
final class ReportService
{
    public function __construct(
        #[Autowire(service: 'nexus.actor_ref.analytics')]
        private readonly ActorRef $analyticsActor,
    ) {}
}
```

> **Note:** The injected `ActorRef` is a worker-local reference. Each Swoole worker thread has its own actor system; the `ActorRef` injected into a worker's container points to that worker's actor instance. References are not shared across workers.

---

## Section 4: tell() vs ask()

### tell() — fire and forget

`tell()` enqueues a message in the actor's mailbox and returns immediately. The caller does not wait for the actor to process the message. Use `tell()` when the response is not needed to build the current response.

```php
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/orders', methods: ['POST'])]
public function place(Request $request): JsonResponse
{
    $this->orderActor->tell(new PlaceOrder(
        customerId: $request->request->getString('customer_id'),
        productId:  $request->request->getString('product_id'),
        qty:        $request->request->getInt('qty'),
    ));

    return new JsonResponse(['status' => 'accepted'], 202);
}
```

`tell()` is non-blocking and has no timeout. The message is guaranteed to be delivered to the mailbox (unless the mailbox is closed or the configured overflow strategy drops it).

### ask() — request-response via Future

`ask()` sends a message and returns a `Future<R>`. The actor must reply using `$ctx->reply($response)` inside its handler. The `Future` resolves when the reply arrives.

```php
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;

/** @return Future<JsonResponse> */
#[Route('/catalog/{id}', methods: ['GET'])]
public function show(string $id): Future
{
    return $this->catalogActor
        ->ask(new GetProduct($id), Duration::seconds(5))
        ->map(static fn(ProductDetail $d) => new JsonResponse($d->product->toArray()));
}
```

The `Duration` argument is the maximum time to wait for a reply. If no reply arrives within the timeout, `AskTimeoutException` is thrown.

**`ask()` is eager**: the message is sent immediately at the time `ask()` is called, not when `await()` or `map()` is called. This makes it possible to issue multiple asks concurrently.

### Future API

| Method | Description |
|---|---|
| `await(): R` | Suspend the current fiber/coroutine until the reply arrives. Throws `FutureException` on timeout or cancellation. |
| `map(Closure(R): U): Future<U>` | Transform the result lazily. Does not block. Returns a new `Future`. |
| `flatMap(Closure(R): Future<U>): Future<U>` | Chain a dependent ask. Lazy. |
| `isResolved(): bool` | Check if the reply has already arrived (non-blocking poll). |
| `cancel(): void` | Cancel the pending ask. Triggers `onCancel` callbacks. |
| `onCancel(Closure): self` | Register a callback invoked when the future is cancelled. |

### Returning a Future from a controller

`NexusBundle` registers a `FutureResponseListener` on Symfony's `kernel.view` event. When a controller returns a `Future`, the listener calls `$future->await()` to resolve it to a concrete `Response` before Symfony continues response handling. This keeps the controller code non-blocking: the Swoole coroutine suspends at `await()`, allowing other coroutines to run on the same worker while waiting for the actor reply.

```php
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

> **Tip:** Returning `Future<JsonResponse>` is the idiomatic pattern for actor-backed controller actions. The type annotation is for Psalm's benefit; Symfony itself receives a `Future` object and the listener handles the resolution.

### Fan-out: multiple concurrent asks

Because `ask()` is eager, multiple asks can be dispatched before any `await()`. Both requests are in-flight simultaneously, and the total latency is the maximum of the two, not the sum.

```php
/** @return Future<JsonResponse> */
public function list(RequestContext $ctx): Future
{
    // Both requests are sent immediately — they execute concurrently.
    $productsFuture = $this->catalogActor->ask(new GetProducts(), Duration::seconds(5));
    $stockFuture    = $this->inventoryActor->ask(new GetStock(self::KNOWN_IDS), Duration::seconds(5));

    $requestId = $ctx->requestId;

    return $productsFuture->map(
        static function (ProductList $list) use ($stockFuture, $requestId): JsonResponse {
            // $stockFuture->await() suspends only if the stock reply has not yet arrived.
            /** @var StockLevel $stock */
            $stock = $stockFuture->await();

            return new JsonResponse([
                'products'  => array_map(
                    static fn(Product $p) => [
                        ...$p->toArray(),
                        'stock' => $stock->levels[$p->id] ?? 0,
                    ],
                    $list->items,
                ),
                'requestId' => $requestId,
            ]);
        },
    );
}
```

In the example above, if `catalogActor` takes 20 ms and `inventoryActor` takes 15 ms, the total wall time is ~20 ms, not 35 ms.

### When to use each

| Scenario | Recommended |
|---|---|
| Fire a command, no result needed (e.g., enqueue a job) | `tell()` |
| Read data that must appear in the HTTP response | `ask()` |
| Kick off a background task from a controller | `tell()` |
| Aggregate results from multiple actors | Multiple concurrent `ask()` calls |
| Chain actor results (`A` depends on reply from `B`) | `ask().flatMap(...)` |

---

## Section 5: Defining Messages

Messages are the unit of communication between actors and callers. They must be `readonly` classes. The Nexus Psalm plugin enforces this rule at static analysis time.

```php
// A query — carries no state, just requests a result.
readonly class GetProducts {}

// A query with parameters.
readonly class GetProduct
{
    public function __construct(public readonly string $id) {}
}

// A query with a list parameter.
readonly class GetStock
{
    /** @param list<string> $productIds */
    public function __construct(public readonly array $productIds) {}
}

// A reply carrying a collection.
readonly class ProductList
{
    /** @param Product[] $items */
    public function __construct(public readonly array $items) {}
}

// A reply carrying a map.
readonly class StockLevel
{
    /** @param array<string, int> $levels */
    public function __construct(public readonly array $levels) {}
}

// A command with no expected reply.
readonly class PlaceOrder
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $productId,
        public readonly int    $qty,
    ) {}
}
```

### Why readonly is required

`readonly` properties prevent mutation after construction. Because an actor may process a message and simultaneously hold a reference to it (e.g., stashing it), mutability would create race conditions between the sender and the actor. The immutability guarantee simplifies reasoning about message passing: once constructed, a message is a value that cannot change.

> **Caution:** Passing mutable objects (arrays with object references, non-readonly classes) as messages is technically possible but defeats the immutability contract and can cause hard-to-reproduce bugs in concurrent environments. The Psalm plugin flags violations when enabled.

### Message naming conventions

- **Commands** — imperative verb phrase: `PlaceOrder`, `ProcessPayment`, `InvalidateCache`.
- **Queries** — question or retrieval phrase: `GetProducts`, `FindOrderById`, `GetStock`.
- **Replies** — noun or past-tense phrase: `ProductList`, `OrderPlaced`, `StockLevel`.

No naming is enforced by the framework; these are conventions that improve readability.

---

## Section 6: WorkerStartBootstrapper — Per-Worker Initialization

`WorkerStartBootstrapper` is an interface for services that must run initialization code once per Swoole worker, before any HTTP request is served. It is the correct place to establish per-worker resources: connection pools, pre-warmed caches, or other coroutine-context-aware objects.

```php
namespace Monadial\Nexus\Symfony\Runtime;

use Psr\Container\ContainerInterface;

interface WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void;
}
```

### Implementing a bootstrapper

```php
use Monadial\Nexus\Symfony\Runtime\WorkerStartBootstrapper;
use Psr\Container\ContainerInterface;

final class ConnectionPoolBootstrapper implements WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        // This method runs once per worker, inside a Swoole coroutine context.
        // It is safe to create coroutine-aware resources here.

        $redisPool = new SwooleRedisPool(
            host: $_ENV['REDIS_HOST'] ?? 'redis',
            port: (int) ($_ENV['REDIS_PORT'] ?? 6379),
            size: (int) ($_ENV['REDIS_POOL_SIZE'] ?? 16),
        );

        SwooleRedisPool::setCurrent($redisPool);
    }
}
```

### Registration

If autoconfiguration is enabled (the default for `App\` namespace), services implementing `WorkerStartBootstrapper` are tagged automatically with `nexus.worker_start`. No explicit tag is needed.

To register manually:

```yaml
# config/services.yaml
App\Infrastructure\ConnectionPoolBootstrapper:
    tags:
        - { name: nexus.worker_start }
```

### What is available in onWorkerStart()

The `$container` argument is the fully booted Symfony kernel container for that worker. Standard services — Doctrine, cache, Messenger transports — are available. However, the actor system and runtime are **not yet available** when `onWorkerStart()` is called; the creation order within `NexusRunner::run()` is:

```
1. Boot Symfony kernel
2. Call all WorkerStartBootstrapper::onWorkerStart() callbacks
3. Create ActorSystem and Runtime, set on container
4. Spawn isolated actors (reads nexus.isolated_actors, calls Props factories)
5. Start the runtime event loop
```

> **Caution:** Do not attempt to inject `ActorSystem` or `Runtime` into a `WorkerStartBootstrapper`. Those services are synthetic and are set into the container in step 3, after bootstrappers run. Accessing them in `onWorkerStart()` will throw a `ServiceNotFoundException`.

### Worker ID

The `$workerId` is a zero-based integer identifying the worker thread. It is unique within the pool for the lifetime of the worker. Use it to partition resources across workers (e.g., different queue partitions per worker) or for logging/tracing.

### Real-world example

The `symfony-demo` example uses a `ConnectionPoolBootstrapper` that initializes both a Redis pool and a PDO pool:

```php
final class ConnectionPoolBootstrapper implements WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        $this->initRedisPool();
        $this->initPDOPool();
    }

    private function initRedisPool(): void
    {
        $dsn  = $_ENV['REDIS_DSN'] ?? 'redis://redis:6379';
        $size = (int) ($_ENV['REDIS_POOL_SIZE'] ?? 16);

        SwooleRedisPool::setCurrent(new SwooleRedisPool(
            host: $this->parseRedisHost($dsn),
            port: $this->parseRedisPort($dsn),
            size: $size,
        ));
    }

    private function initPDOPool(): void
    {
        $url  = $_ENV['DATABASE_URL'] ?? '';
        $size = (int) ($_ENV['DB_POOL_SIZE'] ?? 8);

        if ($url !== '') {
            SwoolePDOPool::setCurrent(SwoolePDOPool::fromDatabaseUrl($url, $size));
        }
    }
}
```

Pools are stored as static singletons on their own classes, accessed by Doctrine middleware and cache adapters without going through the DI container. This avoids synthetic service complexity while remaining transparent to application code.

---

## Section 7: Actor Supervision

Every actor spawned through the Nexus Symfony integration is a child of the worker's `ActorSystem`. The system applies supervision when a child actor's `handle()` method throws an unhandled exception.

### Default strategy

The default strategy is `SupervisionStrategy::oneForOne(maxRetries: 3, window: 60s)` with a `Directive::Restart` on any `Throwable`. This means:

- The failed actor is restarted up to 3 times within a 60-second window.
- Other actors are unaffected.
- After the retry limit is exceeded, the actor is stopped and `MaxRetriesExceededException` is escalated to the parent.

### Custom supervision

Supervision strategy is configured at spawn time via `Props::withSupervision()`. In the Symfony integration, the actor class is spawned via `Props::fromContainer()`. To add a custom strategy, wrap the props in `NexusRunner` configuration (consult the `kernel-pool.md` documentation for details), or use `Behavior::supervise()` in a closure-based actor.

### ChildFailed signal

When a child actor fails and supervision has run, the parent receives a `ChildFailed` signal carrying the child's `ActorRef` and the `Throwable`. For isolated actors, the parent is the actor system root. Application actors do not receive `ChildFailed` from top-level isolated actors by default.

See [Actor Lifecycle — ChildFailed Signal](./actor-lifecycle.md#childfailed-signal) for a detailed example of handling this signal.

---

## Section 8: Scheduling Messages

Actors can schedule messages to themselves using `$ctx->scheduleOnce()` and `$ctx->scheduleRepeatedly()`.

### scheduleOnce()

Delivers a message to the actor's mailbox after a delay:

```php
public function handle(ActorContext $ctx, object $message): Behavior
{
    if ($message instanceof StartRetry) {
        $ctx->scheduleOnce(Duration::seconds(5), new RetryNow());
        return Behavior::same();
    }

    return Behavior::unhandled();
}
```

`scheduleOnce()` returns a `Cancellable`. Call `$cancellable->cancel()` to prevent delivery if the operation completes before the timer fires.

### scheduleRepeatedly()

Delivers a message at a fixed interval, starting after an initial delay:

```php
public function onPreStart(ActorContext $ctx): void
{
    // Poll immediately (zero initial delay), then every second.
    $ctx->scheduleRepeatedly(Duration::zero(), Duration::seconds(1), new Poll());
}
```

The `OrderProcessorActor` in `symfony-demo` uses this pattern to poll a Messenger transport for incoming `PlaceOrder` messages:

```php
#[Actor(ActorType::Shared, 'order-processor')]
final class OrderProcessorActor extends AbstractActor
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
        private readonly EntityManagerInterface $em,
        private readonly TransportInterface $transport,
    ) {}

    public function onPreStart(ActorContext $ctx): void
    {
        $ctx->scheduleRepeatedly(Duration::zero(), Duration::seconds(1), new Poll());
    }

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if (!$message instanceof Poll) {
            return Behavior::unhandled();
        }

        foreach ($this->transport->get() as $envelope) {
            $inner = $envelope->getMessage();

            if ($inner instanceof PlaceOrder) {
                $order = new Order(new Ulid(), $inner->customerId, $inner->productId, $inner->qty);
                $this->em->persist($order);
                $this->em->flush();
                $this->cache->invalidateTags(['inventory']);
                $this->transport->ack($envelope);
            }
        }

        return Behavior::same();
    }
}
```

All timers scheduled via `scheduleRepeatedly()` are automatically cancelled when the actor stops (during `Stopping`). There is no need to cancel them manually in `onPostStop()`.

> **Tip:** The polling pattern — scheduling a recurring `Poll` message in `onPreStart()` — is the standard Nexus equivalent of a Symfony Messenger consumer. It keeps all business logic inside the actor boundary, with the timer managed by the actor runtime rather than an external process.

---

## Configuration Reference

### nexus.yaml

```yaml
# config/packages/nexus.yaml
nexus:
    name: my-app          # Actor system name (appears in actor paths)
    shutdown_timeout: 30  # Seconds to wait for graceful shutdown
```

| Key | Type | Default | Description |
|---|---|---|---|
| `name` | `string` | `app` | Name of the actor system. Used as the root segment of all actor paths. |
| `shutdown_timeout` | `int` | `30` | Seconds to allow for graceful actor shutdown when the Swoole worker stops. |

### Service tags

| Tag | Applied to | Effect |
|---|---|---|
| `nexus.worker_start` | `WorkerStartBootstrapper` implementations | Called once per worker at startup, before actors are spawned. Applied automatically via autoconfiguration. |

### Container parameters

| Parameter | Type | Description |
|---|---|---|
| `nexus.isolated_actors` | `array<string, string>` | Maps actor name → `ActorPropsFactory` service ID. Written by `ActorRegistrationPass`. Read by `NexusRunner` at worker start. |

### Container service IDs (per actor)

| Service ID | Type | Description |
|---|---|---|
| `nexus.actor.{name}.props_factory` | `ActorPropsFactory` | Factory that produces `Props` for the actor. Registered by `ActorRegistrationPass`. |
| `nexus.actor_ref.{name}` | `ActorRef` | Live reference to the actor. Synthetic at compile time; populated by `NexusRunner` at worker start. |

---

## Full Example: CatalogActor with Caching

The following is taken directly from the `symfony-demo` example. It shows a complete actor with DI dependencies, message dispatching, and `ctx->reply()`:

```php
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Actor(ActorType::Isolated, 'catalog')]
final class CatalogActor implements ActorHandler
{
    private const int PRODUCT_TTL = 300;

    public function __construct(private readonly CacheInterface $cache) {}

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof GetProducts) {
            return $this->handleGetProducts($ctx);
        }

        if ($message instanceof GetProduct) {
            return $this->handleGetProduct($ctx, $message);
        }

        return Behavior::unhandled();
    }

    private function handleGetProducts(ActorContext $ctx): Behavior
    {
        $items = array_map(
            function (Product $p): Product {
                /** @var Product $cached */
                $cached = $this->cache->get(
                    "catalog.product.{$p->id}",
                    static function (ItemInterface $item) use ($p): Product {
                        $item->expiresAfter(self::PRODUCT_TTL);
                        return $p;
                    },
                );

                return $cached;
            },
            $this->seeds(),
        );

        $ctx->reply(new ProductList($items));

        return Behavior::same();
    }

    private function handleGetProduct(ActorContext $ctx, GetProduct $message): Behavior
    {
        $seed = $this->findSeed($message->id);

        if ($seed === null) {
            return Behavior::same();
        }

        /** @var Product $product */
        $product = $this->cache->get(
            "catalog.product.{$message->id}",
            static function (ItemInterface $item) use ($seed): Product {
                $item->expiresAfter(self::PRODUCT_TTL);
                return $seed;
            },
        );

        $ctx->reply(new ProductDetail($product));

        return Behavior::same();
    }

    /** @return Product[] */
    private function seeds(): array
    {
        return [
            new Product('A comfortable ergonomic chair', 'chair-001', 'Ergonomic Chair', 299.99),
            new Product('Height-adjustable standing desk', 'desk-001', 'Standing Desk', 499.99),
            new Product('High-performance USB-C hub', 'hub-001', 'USB-C Hub', 79.99),
        ];
    }

    private function findSeed(string $id): ?Product
    {
        foreach ($this->seeds() as $product) {
            if ($product->id === $id) {
                return $product;
            }
        }

        return null;
    }
}
```

And the controller that uses it with a concurrent fan-out:

```php
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    private const array KNOWN_IDS = ['chair-001', 'desk-001', 'hub-001'];

    public function __construct(
        #[Autowire(service: 'nexus.actor_ref.catalog')]
        private readonly ActorRef $catalogActor,
        #[Autowire(service: 'nexus.actor_ref.inventory')]
        private readonly ActorRef $inventoryActor,
    ) {}

    /** @return Future<JsonResponse> */
    #[Route('/catalog', methods: ['GET'])]
    public function list(RequestContext $ctx): Future
    {
        $productsFuture = $this->catalogActor->ask(new GetProducts(), Duration::seconds(5));
        $stockFuture    = $this->inventoryActor->ask(new GetStock(self::KNOWN_IDS), Duration::seconds(5));
        $requestId      = $ctx->requestId;

        return $productsFuture->map(
            static function (ProductList $list) use ($stockFuture, $requestId): JsonResponse {
                /** @var StockLevel $stock */
                $stock = $stockFuture->await();

                return new JsonResponse([
                    'products'  => array_map(
                        static fn(Product $p) => [
                            ...$p->toArray(),
                            'stock' => $stock->levels[$p->id] ?? 0,
                        ],
                        $list->items,
                    ),
                    'requestId' => $requestId,
                ]);
            },
        );
    }

    /** @return Future<JsonResponse> */
    #[Route('/catalog/{id}', methods: ['GET'])]
    public function show(string $id): Future
    {
        return $this->catalogActor
            ->ask(new GetProduct($id), Duration::seconds(5))
            ->map(static fn(ProductDetail $d) => new JsonResponse($d->product->toArray()));
    }
}
```
