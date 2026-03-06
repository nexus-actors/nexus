# Symfony Demo Application Design

**Location:** `examples/symfony-demo/`
**Purpose:** Production-pattern example of Nexus + Symfony on Swoole with Redis cache and Doctrine persistence.

---

## System Topology

Two OS-level processes communicate through Redis (cache + Messenger transport):

```
┌───────────────────────────────────────────────┐    Redis     ┌────────────────────────────────────────────────┐
│  HTTP Process  (NexusRunner)                  │ ←──────────→ │  Worker Pool  (NexusSymfonyWorkerApp)          │
│  Swoole HTTP server, 4 workers                │  cache +     │  4 ZTS threads, Swoole\Thread\Pool             │
│                                               │  queue       │                                                │
│  CatalogActor   #[Isolated]  per worker       │              │  OrderProcessorActor  #[Shared]  per thread   │
│  InventoryActor #[Isolated]  per worker       │              │  ConsumerActor        #[Shared]  per thread   │
│  RequestContext #[CoroutineScoped]            │              │  SchedulerActor       #[Shared]  per thread   │
└───────────────────────────────────────────────┘              └────────────────────────────────────────────────┘
         reads products + stock                                          writes orders (Doctrine/MySQL)
         from Redis tagged cache                                         polls Redis Messenger queue
```

---

## Docker Composition

### Services

| Service | Image target | Role |
|---------|-------------|------|
| `app` | `php-full` (ZTS + Swoole + Xdebug) | HTTP server: `php public/index.php` |
| `worker` | `php-swoole` (ZTS + Swoole, no Xdebug) | Worker pool: `php bin/worker` |
| `mysql` | `mysql:8.0` | Order persistence |
| `redis` | `redis:7-alpine` | Cache + Messenger transport |

### Dockerfile targets (extends monorepo pattern)

| Target | Base | Extensions |
|--------|------|------------|
| `base-zts` | `php:8.5-zts` | bcmath, intl, pdo_mysql, redis (PECL) |
| `swoole-build` | `base-zts` | Swoole 6.2 with `--enable-swoole-thread` |
| `php-swoole` | `base-zts` | Swoole (from build stage) |
| `php-full` | `php-swoole` | + Xdebug |

The Redis PECL extension (`ext-redis`) is required for both `symfony/cache` and Swoole coroutine-native Redis connections.

---

## Actor Design

### HTTP workers (spawned by `NexusRunner::bootIsolatedActors`)

**`CatalogActor`** `#[Actor(ActorType::Isolated, 'catalog')]`
- Constructor: `CacheInterface $cache`
- Handles: `GetProducts`, `GetProduct(string $id)`
- Replies with: `ProductList(Product[] $items)`, `ProductDetail(Product $product)`
- Cache key pattern: `catalog.product.$id` (TTL 300 s), tag `catalog`
- On cache miss: returns hard-coded seed data (demo) and warms the cache

**`InventoryActor`** `#[Actor(ActorType::Isolated, 'inventory')]`
- Constructor: `CacheInterface $cache`
- Handles: `GetStock(string[] $productIds)`
- Replies with: `StockLevel(array<string, int> $levels)`
- Cache key pattern: `inventory.stock.$productId` (TTL 60 s), tag `inventory`

### Worker-pool threads (spawned by `NexusSymfonyWorkerApp`)

**`OrderProcessorActor`** `#[Actor(ActorType::Shared, 'order-processor')]`
- Constructor: `EntityManagerInterface $em, CacheInterface $cache`
- Handles: `ProcessOrder(string $customerId, string $productId, int $qty, string $orderId)`
- Persists `Order` entity via Doctrine, flushes, invalidates `inventory` cache tag
- Replies with: `OrderProcessed(string $orderId, string $status)`

**`ConsumerActor`** — already in `nexus-symfony-messenger`
- Handles: `ConsumeFromTransport('orders', 10)`
- Polls Redis Messenger transport, dispatches envelopes onto bus

**`SchedulerActor`** — already in `nexus-symfony`
- Registered via `RegisterSchedule` in `WorkerPoolStartup::configure()`
- Every 1 second: tells `ConsumerActor` `ConsumeFromTransport('orders', 10)`

---

## Data Flow

### `GET /catalog` — fan-out parallel ask

```
CatalogController::list(RequestContext $ctx)
  $productsFuture  = catalogActor->ask(new GetProducts(), Duration::seconds(5))
  $stockFuture     = inventoryActor->ask(new GetStock([...]), Duration::seconds(5))
  // both actors process concurrently in their mailboxes

  return $productsFuture->map(
      function (ProductList $list) use ($stockFuture, $ctx): JsonResponse {
          $stock = $stockFuture->await();   // suspends coroutine, non-blocking
          return new JsonResponse([
              'requestId' => $ctx->requestId,
              'products'  => array_map(
                  fn(Product $p) => [
                      ...$p->toArray(),
                      'stock' => $stock->levels[$p->id] ?? 0,
                  ],
                  $list->items,
              ),
          ]);
      }
  );
  // FutureResponseListener awaits the outer Future and sets the HTTP response
```

### `GET /catalog/{id}` — single ask

```
CatalogController::show(string $id)
  return catalogActor
      ->ask(new GetProduct($id), Duration::seconds(5))
      ->map(fn(ProductDetail $d) => new JsonResponse($d->product->toArray()));
```

### `POST /orders` — fire into Messenger queue, immediate 202

```
OrderController::place(Request $request, RequestContext $ctx)
  $dto = OrderDto::fromRequest($request)
  $this->bus->dispatch(new PlaceOrder($dto->customerId, $dto->productId, $dto->qty))
  return new JsonResponse(['status' => 'queued', 'requestId' => $ctx->requestId], 202)
```

### Worker poll → Doctrine write

```
SchedulerActor [every 1s]
  → tells ConsumerActor: new ConsumeFromTransport('orders', 10)
ConsumerActor
  → polls Redis transport, dispatches PlaceOrder envelope onto bus
PlaceOrderHandler::__invoke(PlaceOrder $msg)                     // in actor coroutine
  $ref    = $container->get('nexus.actor_ref.order-processor')   // WorkerActorRef<ProcessOrder>
  $result = $ref->ask(new ProcessOrder(...), Duration::seconds(5))->await()
  $transport->ack($envelope)
OrderProcessorActor::handle(ProcessOrder $msg)
  $order = new Order(new Ulid(), $msg->customerId, $msg->productId, $msg->qty)
  $this->em->persist($order); $this->em->flush()
  $this->cache->invalidateTags(['inventory'])
  ctx->reply(new OrderProcessed($order->id->toRfc4122(), 'accepted'))
```

---

## Messenger Bridge

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            orders:
                dsn: '%env(REDIS_DSN)%/orders'
                options: { stream: orders }
        routing:
            'App\Message\PlaceOrder': orders
```

`PlaceOrderHandler` is tagged `messenger.message_handler`. It runs inside the worker pool when the `ConsumerActor` dispatches the envelope onto the bus.

---

## Doctrine Setup

**Entity:** `Order`
- `id`: `Ulid` (ULID, mapped as string)
- `customerId`: `string`
- `productId`: `string`
- `qty`: `int`
- `status`: `string` (`pending` | `accepted` | `failed`)
- `createdAt`: `DateTimeImmutable`

Doctrine runs **only** in the worker pool threads. Each thread boots its own `EntityManagerInterface` via fresh kernel — no shared state, no coroutine concerns.

---

## `RequestContext` Service

```php
#[CoroutineScoped]
final class RequestContext
{
    public readonly string $requestId;
    public readonly float  $startedAt;

    public function __construct()
    {
        $this->requestId = (string) new Ulid();
        $this->startedAt = microtime(true);
    }

    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }
}
```

Injected into controllers via constructor. Each Swoole coroutine (request) gets its own instance.

---

## Coding Standards (identical to monorepo)

| Tool | Config | Rule |
|------|--------|------|
| PHP-CS-Fixer | `.php-cs-fixer.dist.php` | `@PER-CS2.0`, `@PER-CS2.0:risky`, `declare_strict_types`, `ordered_imports`, `trailing_comma_in_multiline` |
| PHPCS | `phpcs.xml` | Full Slevomat ruleset (identical exclusions to monorepo) |
| Psalm | `psalm.xml` | Level 1, `findUnusedCode=true`, `reportMixedIssues=true`, Nexus plugin |
| GrumPHP | `grumphp.yml` | Pre-commit: phpcsfixer → phpcs → psalm → phpunit (unit suite) |
| PHPUnit | `phpunit.xml` | `failOnRisky=true`, `failOnWarning=true`, `colors=true` |

All classes `final`. All value objects `readonly`. All actor messages `readonly`. Array keys sorted alphabetically. Blank line before `if`/`foreach`/`while`/`try`. Multi-line ternary operators.

---

## Test Organisation

```
tests/
└── Unit/
    ├── Actor/
    │   ├── CatalogActorTest.php         covers: cache hit, cache miss, GetProduct
    │   ├── InventoryActorTest.php        covers: multi-product stock lookup
    │   └── OrderProcessorActorTest.php  covers: persist + cache invalidation
    ├── Controller/
    │   ├── CatalogControllerTest.php    covers: Future<Response> from fan-out
    │   └── OrderControllerTest.php      covers: 202 dispatch
    └── MessageHandler/
        └── PlaceOrderHandlerTest.php    covers: ask OrderProcessorActor → ack
```

All tests follow monorepo pattern: `#[CoversClass]`, `#[Test]`, `final class`, `TestCase` base, `createStub` for no-expectation doubles, `createMock` for expectations.

---

## File Structure

```
examples/symfony-demo/
├── Makefile
├── composer.json
├── grumphp.yml
├── phpcs.xml
├── phpunit.xml
├── psalm.xml
├── .php-cs-fixer.dist.php
├── .env
├── docker/
│   └── Dockerfile
├── docker-compose.yml
├── public/
│   └── index.php                          HTTP entry: NexusRuntime
├── bin/
│   └── worker                             Worker pool entry: NexusSymfonyWorkerApp
├── config/
│   ├── bundles.php
│   └── packages/
│       ├── doctrine.yaml
│       ├── framework.yaml
│       ├── messenger.yaml
│       └── nexus.yaml
├── src/
│   ├── Kernel.php
│   ├── Actor/
│   │   ├── CatalogActor.php
│   │   ├── InventoryActor.php
│   │   ├── OrderProcessorActor.php
│   │   └── Message/
│   │       ├── GetProduct.php
│   │       ├── GetProducts.php
│   │       ├── GetStock.php
│   │       ├── OrderProcessed.php
│   │       ├── ProcessOrder.php
│   │       ├── Product.php
│   │       ├── ProductDetail.php
│   │       ├── ProductList.php
│   │       └── StockLevel.php
│   ├── Controller/
│   │   ├── CatalogController.php
│   │   └── OrderController.php
│   ├── Entity/
│   │   └── Order.php
│   ├── Message/
│   │   └── PlaceOrder.php
│   ├── MessageHandler/
│   │   └── PlaceOrderHandler.php
│   └── Service/
│       └── RequestContext.php
└── tests/
    └── Unit/
        ├── Actor/
        │   ├── CatalogActorTest.php
        │   ├── InventoryActorTest.php
        │   └── OrderProcessorActorTest.php
        ├── Controller/
        │   ├── CatalogControllerTest.php
        │   └── OrderControllerTest.php
        └── MessageHandler/
            └── PlaceOrderHandlerTest.php
```
