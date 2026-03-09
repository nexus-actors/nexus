# Doctrine and database access

## Architecture overview

```
HTTP Request
     │
     ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Swoole Worker Thread                                               │
│                                                                     │
│  KernelPoolActor                                                    │
│       │  (dispatches to idle kernel)                                │
│       ▼                                                             │
│  KernelActor  ─── spawns coroutine ──►  Coroutine N (Request)      │
│                                              │                      │
│                                              ▼                      │
│                                         Controller                  │
│                                              │                      │
│                                              ▼                      │
│                                         Service / Repository        │
│                                              │                      │
│                                              ▼                      │
│                                         EntityManager               │
│                                              │  (DBAL Connection)   │
│                                              ▼                      │
│                                    SwoolePoolMiddleware              │
│                                              │  (wraps Driver)      │
│                                              ▼                      │
│                                    SwoolePoolDriver.connect()       │
│                                              │                      │
│                                              ▼                      │
│                                    CoroutineLocalConnection         │
│                                              │  (lazy borrow)       │
│                                              ▼                      │
│                              ┌───────────────────────────┐         │
│                              │  SwoolePDOPool             │         │
│                              │  [PDO] [PDO] [PDO] [PDO]  │         │
│                              └───────────────────────────┘         │
│                                              │                      │
│                                              ▼                      │
│                                          MySQL / PostgreSQL         │
└─────────────────────────────────────────────────────────────────────┘
```

The coroutine boundary is the key architectural point. The pool holds a fixed number of physical PDO connections. Each coroutine borrows one connection when it first issues a query. Swoole's `Coroutine::defer()` returns the connection to the pool when the coroutine ends, regardless of success or failure.

---

## The problem with standard Doctrine under Swoole

### Standard Doctrine assumes single-request execution

Doctrine's `EntityManager` is built for PHP-FPM: one process, one request, one unit of work. It maintains:

- An **identity map** — a cache of all entities loaded from the database, keyed by class + primary key.
- A **unit of work** — a change tracking set recording which entities are new, dirty, or removed.
- A single **DBAL Connection** wrapping a single PDO handle.

Under PHP-FPM all three are private to the request's process. Under Swoole with a default configuration, all three are shared across every concurrent request in the worker.

### The timing diagram

```
Time ──────────────────────────────────────────────────────────►

Coroutine A (Request 1)              Coroutine B (Request 2)
────────────────────────────────     ────────────────────────────────
$em->find(Order::class, '01HX...')
// loads Order, stores in identity map
// SELECT ... FROM orders WHERE id=?
                                     $em->find(Order::class, '01HX...')
                                     // RETURNS CACHED OBJECT from identity map
                                     // no database hit — stale data

// A suspends on another query
                                     $order->setStatus('shipped');
                                     $em->flush();
                                     // writes B's change to DB

// A resumes
$order = $em->find(Order::class, '01HX...');
// still returns the cached object
// A's order object now has status 'shipped' — wrong for A's context

// A also tries to flush its changes
$em->flush();
// Doctrine sees A's object as dirty (it changed), writes again
// B's committed data may be overwritten
```

The identity map makes `find()` appear fast but returns objects that can be in any state. The unit of work may produce writes interleaved with another request's writes.

### The connection layer problem

Beyond the identity map, the physical connection is shared. If Request A begins a transaction:

```
Coroutine A: BEGIN TRANSACTION
Coroutine A: SELECT ... (suspends to wait for result)
Coroutine B: INSERT INTO orders ... (runs on the same connection)
             — B's INSERT runs inside A's open transaction —
Coroutine A: COMMIT — commits both A's and B's writes
```

This is not hypothetical. Swoole's coroutine scheduler will switch to Coroutine B during the network round-trip of A's SELECT. B will issue its INSERT on the same connection because both coroutines share the same `EntityManager`, which holds a single connection.

---

## SwoolePoolDriver

`SwoolePoolDriver` is a Doctrine DBAL driver that replaces the `connect()` method with a coroutine-local pooled connection:

```php
final class SwoolePoolDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): Connection
    {
        $pool = SwoolePDOPool::current();
        $cid  = \Swoole\Coroutine::getCid();

        if ($pool === null || $cid === -1) {
            return parent::connect($params);
        }

        return new CoroutineLocalConnection($pool);
    }
}
```

`AbstractDriverMiddleware` is a Doctrine DBAL base class that delegates all driver methods to the wrapped driver. `SwoolePoolDriver` overrides only `connect()`.

When called within a Swoole coroutine with an active pool, `connect()` returns a `CoroutineLocalConnection` instead of opening a real PDO connection. The `CoroutineLocalConnection` borrows from the pool lazily on first query.

When called outside a coroutine (CLI scripts, test runners) — `Coroutine::getCid()` returns `-1` — the method falls through to `parent::connect()`, which uses the wrapped driver's normal connection logic. No pool, no coroutine scope, standard PDO behavior.

### CoroutineLocalConnection in detail

`CoroutineLocalConnection` implements `Doctrine\DBAL\Driver\Connection`. It holds two maps keyed by coroutine ID:

```php
final class CoroutineLocalConnection implements Connection
{
    /** @var array<int, PDOConnection> */
    private array $localConnections = [];

    /** @var array<int, object> */
    private array $localPdos = [];

    public function __construct(private readonly SwoolePDOPool $pool) {}

    private function inner(): PDOConnection
    {
        $cid = \Swoole\Coroutine::getCid();
        $key = $cid === -1 ? 0 : $cid;

        if (!isset($this->localConnections[$key])) {
            $pdo                          = $this->pool->get();
            $this->localPdos[$key]        = $pdo;
            /** @var \PDO $pdoTyped */
            $pdoTyped                     = $pdo;
            $this->localConnections[$key] = new PDOConnection($pdoTyped);

            if ($cid !== -1) {
                \Swoole\Coroutine::defer(function () use ($key): void {
                    if (isset($this->localPdos[$key])) {
                        $this->pool->put($this->localPdos[$key]);
                        unset($this->localPdos[$key], $this->localConnections[$key]);
                    }
                });
            }
        }

        return $this->localConnections[$key];
    }

    public function prepare(string $sql): Statement { return $this->inner()->prepare($sql); }
    public function query(string $sql): Result       { return $this->inner()->query($sql); }
    public function quote(mixed $value): string      { return $this->inner()->quote($value); }
    public function exec(string $sql): int           { return $this->inner()->exec($sql); }
    public function lastInsertId(): int|string       { return $this->inner()->lastInsertId(); }
    public function beginTransaction(): void         { $this->inner()->beginTransaction(); }
    public function commit(): void                   { $this->inner()->commit(); }
    public function rollBack(): void                 { $this->inner()->rollBack(); }
    public function getNativeConnection(): mixed     { return $this->inner()->getNativeConnection(); }
    public function getServerVersion(): string       { return $this->inner()->getServerVersion(); }
}
```

Every public method calls `inner()`, which performs the lazy borrow and returns the coroutine-local `PDOConnection`. The `PDOConnection` wrapper is Doctrine's own adapter; `CoroutineLocalConnection` delegates to it so that Doctrine's statement preparation, result iteration, and metadata handling work unchanged.

The `Coroutine::defer()` callback fires when the coroutine ends — whether it ends normally, via exception, or due to server shutdown. This guarantees that the PDO handle is returned to the pool and the map entries are cleared.

---

## SwoolePoolMiddleware

Doctrine DBAL 3+ provides a `Middleware` interface for composing driver behavior. `SwoolePoolMiddleware` uses it to wrap the real driver with `SwoolePoolDriver`:

```php
final class SwoolePoolMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SwoolePoolDriver($driver);
    }
}
```

`Middleware::wrap()` is called by DBAL during connection setup. The chain becomes:

```
EntityManager → DBAL Connection → SwoolePoolDriver → CoroutineLocalConnection → SwoolePDOPool → PDO
```

`SwoolePoolMiddleware` is a standard Symfony service. Registering it with the `doctrine.middleware` tag is sufficient — Doctrine's Symfony integration discovers tagged middlewares and applies them automatically.

---

## Setting up the connection pool

### Environment variables

```dotenv
DATABASE_URL=mysql://app:secret@db:3306/myapp?serverVersion=8.0
DB_POOL_SIZE=8
```

### SwoolePDOPool

`SwoolePDOPool` wraps Swoole's `Database\PDOPool`, which manages a fixed-size pool of PDO connections:

```php
final class SwoolePDOPool
{
    private static ?self $current = null;

    private PDOPool $inner;

    public function __construct(
        string $driver,
        string $host,
        int    $port,
        string $dbname,
        string $username,
        string $password,
        string $charset,
        int    $size,
    ) {
        $config = (new PDOConfig())
            ->withDriver($driver)
            ->withHost($host)
            ->withPort($port)
            ->withDbname($dbname)
            ->withUsername($username)
            ->withPassword($password)
            ->withCharset($charset);

        $this->inner = new PDOPool($config, $size);
    }

    public static function current(): ?self  { return self::$current; }
    public static function setCurrent(self $pool): void { self::$current = $pool; }

    public function get(): object      { return $this->inner->get(); }
    public function put(object $pdo): void { $this->inner->put($pdo); }
    public function close(): void
    {
        $this->inner->close();
        self::$current = null;
    }
}
```

`get()` blocks the current coroutine until a connection is available — it does not block the OS thread. If all connections are in use, the coroutine suspends and is resumed when another coroutine returns a connection via `put()`.

`SwoolePDOPool::current()` returns the pool for the current worker. It is `null` outside a worker context (before `ConnectionPoolBootstrapper::onWorkerStart()` has run).

### doctrine.yaml configuration

The minimal DBAL configuration that activates the middleware:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
    orm:
        auto_mapping: true
        mappings:
            App:
                is_bundle: false
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'App\Entity'
                alias: App
```

The middleware is not referenced in `doctrine.yaml` — it is discovered via the `doctrine.middleware` service tag defined in `services.yaml`.

### services.yaml for the middleware

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'
        exclude:
            - '../src/Entity/'
            - '../src/Kernel.php'

    App\Doctrine\SwoolePoolMiddleware:
        tags:
            - { name: doctrine.middleware }
```

When Doctrine DBAL builds its connection, it collects all services tagged `doctrine.middleware`, calls `wrap()` on each, and chains the result. `SwoolePoolMiddleware` inserts `SwoolePoolDriver` at the head of the chain.

---

## ConnectionPoolBootstrapper

`ConnectionPoolBootstrapper` implements `WorkerStartBootstrapper` to initialize `SwoolePDOPool` once per worker before any request is handled:

```php
final class ConnectionPoolBootstrapper implements WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        $this->initRedisPool();
        $this->initPDOPool();
    }

    private function initPDOPool(): void
    {
        $url  = (string) ($_ENV['DATABASE_URL'] ?? '');
        $size = (int) ($_ENV['DB_POOL_SIZE'] ?? 8);

        if ($url === '') {
            return;
        }

        $parsed = $this->parseDatabaseUrl($url);

        if ($parsed === null) {
            return;
        }

        SwoolePDOPool::setCurrent(new SwoolePDOPool(
            driver:   $parsed['driver'],
            host:     $parsed['host'],
            port:     $parsed['port'],
            dbname:   $parsed['dbname'],
            username: $parsed['username'],
            password: $parsed['password'],
            charset:  $parsed['charset'],
            size:     $size,
        ));
    }
}
```

`NexusRunner` discovers `ConnectionPoolBootstrapper` via the `nexus.worker_start` tag (applied automatically by autoconfiguration) and calls `onWorkerStart()` during the worker's startup coroutine.

### Why static singletons, not DI

The pools are stored as static class properties (`SwoolePDOPool::$current`) rather than as Symfony container services. This is intentional. The container may be serialized to a cache file and deserialized at process start. Container services are resolved before `WorkerStartBootstrapper` callbacks fire. If the pool were a container service, Doctrine would attempt to resolve the `EntityManager` (which depends on the connection, which depends on the pool) before the pool has been initialized.

Static singletons break this dependency cycle: the pool is created on first `onWorkerStart()` call and accessed by `SwoolePoolDriver::connect()` on first query. The container never holds a reference to the pool; it holds only `SwoolePoolMiddleware`, which calls `SwoolePDOPool::current()` at query time.

---

## Entity Manager usage

Once configured, `EntityManager` usage is identical to a standard Symfony application. Controllers, repositories, and services inject it normally:

```php
#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(type: 'string')]
    private string $customerId;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string')]
    private string $productId;

    #[ORM\Column(type: 'integer')]
    private int $qty;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    public function __construct(Ulid $id, string $customerId, string $productId, int $qty)
    {
        $this->id         = $id->toBase32();
        $this->customerId = $customerId;
        $this->createdAt  = new DateTimeImmutable();
        $this->productId  = $productId;
        $this->qty        = $qty;
        $this->status     = 'accepted';
    }

    public function id(): string     { return $this->id; }
    public function status(): string { return $this->status; }
}
```

Repository using `EntityManager` directly:

```php
final class OrderRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function findById(string $id): ?Order
    {
        return $this->em->find(Order::class, $id);
    }

    public function save(Order $order): void
    {
        $this->em->persist($order);
        $this->em->flush();
    }

    /** @return list<Order> */
    public function findRecent(int $limit = 10): array
    {
        return $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
```

Controller using the repository:

```php
final class OrderController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route('/orders', methods: ['POST'])]
    public function place(Request $request, RequestContext $ctx): JsonResponse
    {
        /** @var array{customerId: string, productId: string, qty: int} $body */
        $body  = json_decode((string) $request->getContent(), true);
        $order = new Order(new Ulid(), $body['customerId'], $body['productId'], $body['qty']);

        $this->orders->save($order);

        return new JsonResponse([
            'id'        => $order->id(),
            'requestId' => $ctx->requestId,
            'status'    => $order->status(),
        ], 201);
    }

    #[Route('/orders', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $orders = $this->orders->findRecent(10);

        return new JsonResponse(
            array_map(
                static fn(Order $o) => ['id' => $o->id(), 'status' => $o->status()],
                $orders,
            ),
        );
    }
}
```

The application code is indistinguishable from a standard Symfony + Doctrine application. The pool plumbing operates transparently inside the DBAL layer.

---

## Transactions

### Coroutine-level isolation

Because each coroutine gets its own PDO connection (borrowed on first use), transactions are naturally isolated. There is no mechanism by which Coroutine A's `beginTransaction()` can affect Coroutine B's queries — they are running on different physical connections.

```
Coroutine A                          Coroutine B
──────────────────────────────────   ──────────────────────────────────
$conn->beginTransaction()            $conn->beginTransaction()
// A's connection: conn-3            // B's connection: conn-7 (different)

$conn->exec('UPDATE orders ...')     $conn->exec('INSERT INTO orders ...')
// runs on conn-3                    // runs on conn-7

// A suspends
                                     $conn->commit()
                                     // commits conn-7's transaction only
// A resumes
$conn->rollBack()
// rolls back conn-3's transaction only
```

No cross-coroutine transaction contamination is possible.

### Using transactions with EntityManager

```php
$this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($order): void {
    $em->persist($order);

    // Any queries here run on the same coroutine-local connection
    // and are inside the same transaction.
    $em->flush();
});
```

`wrapInTransaction()` opens a transaction, runs the callback, and commits. If the callback throws, it rolls back. The connection is the coroutine-local one.

For explicit transaction control:

```php
$conn = $this->em->getConnection();

try {
    $conn->beginTransaction();

    $this->em->persist($order);
    $this->em->flush();

    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollBack();
    throw $e;
}
```

Always explicitly roll back on exception. A connection returned to the pool with an open transaction will corrupt the next coroutine that borrows it.

---

## Doctrine migrations

Doctrine migrations run as a single CLI process, not inside a Swoole worker. The `bin/console` command is a standard PHP CLI process without Swoole coroutines. `Coroutine::getCid()` returns `-1`, so `SwoolePoolDriver::connect()` falls through to `parent::connect()` — a normal PDO connection, no pool.

Run migrations as usual:

```bash
docker compose exec php bin/console doctrine:migrations:migrate
```

Or generate a new migration:

```bash
docker compose exec php bin/console doctrine:migrations:diff
```

No special handling is required. The Swoole-specific middleware is transparent to the CLI.

For production deployments, run migrations before starting the Nexus server, or use a dedicated migration job in a CI/CD pipeline. Do not run migrations concurrently with a live server that is handling requests against the same database schema.

---

## Testing

### Unit tests: SQLite in-memory

Unit tests for repositories and services can use SQLite in-memory. SQLite does not require a running database server and completes in milliseconds.

Configure a separate `test` connection in Symfony:

```yaml
# config/packages/test/doctrine.yaml
doctrine:
    dbal:
        url: 'sqlite:///:memory:'
    orm:
        auto_schema_tool: update
```

With `auto_schema_tool: update`, Doctrine creates all mapped tables at test bootstrap. Use PHPUnit's kernel booting:

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OrderRepository $repository;

    protected function setUp(): void
    {
        $kernel           = self::bootKernel(['environment' => 'test']);
        $container        = $kernel->getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->repository = new OrderRepository($this->em);
    }

    #[Test]
    public function itPersistsAndRetrievesAnOrder(): void
    {
        $order = new Order(new Ulid(), 'customer-1', 'chair-001', 2);

        $this->repository->save($order);
        $this->em->clear();

        $found = $this->repository->findById($order->id());

        self::assertNotNull($found);
        self::assertSame('accepted', $found->status());
    }
}
```

`$this->em->clear()` between persist and find ensures the test hits the database rather than the identity map.

### Integration tests: real database

For tests that must run against MySQL or PostgreSQL, use a dedicated test database:

```dotenv
# .env.test
DATABASE_URL=mysql://test:test@db:3306/myapp_test
```

Reset the schema between test runs:

```bash
docker compose exec php bin/console doctrine:schema:drop --force --env=test
docker compose exec php bin/console doctrine:schema:create --env=test
```

Or use Doctrine's test fixtures library (`doctrine/doctrine-fixtures-bundle`) to load a known dataset before each test suite.

### Testing without Swoole: FiberRuntime

Tests that exercise the actor layer but not the HTTP layer can use `FiberRuntime`. `SwoolePoolDriver` falls back to `parent::connect()` because `Coroutine::getCid()` returns `-1` in FiberRuntime. The EntityManager uses a standard PDO connection from Doctrine's normal connection resolver.

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

final class OrderProcessorActorTest extends TestCase
{
    #[Test]
    public function itProcessesAnOrder(): void
    {
        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        // ... spawn actor, send messages, assert side effects
    }
}
```

---

## Pool sizing

### Formula

```
pool_size × workers <= database_max_connections

Recommended:
pool_size = ceil(max_concurrent_db_queries_per_worker × safety_factor)
```

Where `max_concurrent_db_queries_per_worker` is approximately `kernel_pool_size` — the number of HTTP requests in flight simultaneously in one worker.

Example with `workers=4`, `kernel_pool_size=8`, `max_connections=150`:

```
max_pool_size = floor(150 / 4) = 37
```

Setting `DB_POOL_SIZE=10` per worker keeps total connections at 40, just above `max_connections=150/4=37`. A safety margin of 5-10% is advisable for monitoring and admin connections.

Reserve additional connections for:

- Doctrine migration CLI runs
- Admin tools and monitoring agents
- Read replicas, if separate pools are configured

### Monitoring pool exhaustion

When all pool connections are in use, `SwoolePDOPool::get()` suspends the calling coroutine. If connections are never returned (application bug, long-running transaction), coroutines accumulate waiting for the pool. Swoole's stats API shows this:

```php
$stats = \Swoole\Coroutine::stats();
echo $stats['coroutine_num']; // number of live coroutines
```

A `coroutine_num` far exceeding `workers × kernel_pool_size` indicates coroutines are stacked waiting for pool connections. Increase `DB_POOL_SIZE`, reduce query latency, or investigate connection leaks.

---

## Troubleshooting

### "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away"

The MySQL server closed an idle connection. The PDO connection in the pool is stale.

Cause: MySQL's `wait_timeout` (default 8 hours) or `interactive_timeout` expired while the connection was idle in the pool. The pool does not ping connections before lending them.

Solutions:

- Set `wait_timeout` in MySQL to a value higher than the worker's idle time.
- Configure the PDO connection with `PDO::ATTR_TIMEOUT` and catch the error in a retry wrapper.
- Add a health-check ping in `SwoolePDOPool::get()` before returning the connection.

A minimal retry wrapper:

```php
private function queryWithRetry(string $sql, array $params = []): array
{
    $pool = SwoolePDOPool::current();
    assert($pool !== null);

    $pdo = $pool->get();

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), '2006') || str_contains($e->getMessage(), '2013')) {
            // Connection lost — close and let the pool create a fresh one.
            $pool->put($pdo);
            throw $e;
        }

        throw $e;
    } finally {
        // Only put back on success; pool will detect and replace dead connections.
        if (isset($stmt)) {
            $pool->put($pdo);
        }
    }
}
```

### "There is already an active transaction"

Cause: a coroutine called `beginTransaction()` and then exited without committing or rolling back. The connection was returned to the pool mid-transaction. The next coroutine to borrow it inherits the open transaction.

Debug by enabling `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` and catching `PDOException` for "active transaction" messages. Add stack traces to identify the code path that exits without finalizing the transaction.

Solution: always use `wrapInTransaction()` or explicit `try/catch/rollBack` blocks. Consider adding a check in `SwoolePDOPool::put()`:

```php
public function put(object $pdo): void
{
    /** @var \PDO $pdo */
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $this->inner->put($pdo);
}
```

This ensures the pool always receives clean connections.

### "Deadlock found when trying to get lock; try restarting transaction"

MySQL deadlocks occur when two connections hold locks the other needs. Under high concurrency with multiple actors and HTTP coroutines accessing the same rows, deadlocks become more likely.

Doctrine throws a `UniqueConstraintViolationException` or `DriverException` wrapping the deadlock error code (1213). Implement retry logic at the service layer:

```php
private function retryOnDeadlock(callable $operation, int $maxRetries = 3): mixed
{
    $attempt = 0;

    while (true) {
        try {
            return $operation();
        } catch (\Doctrine\DBAL\Exception $e) {
            if ($attempt >= $maxRetries || !$this->isDeadlock($e)) {
                throw $e;
            }

            ++$attempt;
            // Brief cooperative yield before retry.
            \Swoole\Coroutine::sleep(0.01 * $attempt);
        }
    }
}

private function isDeadlock(\Doctrine\DBAL\Exception $e): bool
{
    return str_contains($e->getMessage(), '1213') || str_contains($e->getMessage(), 'Deadlock');
}
```

### "Pool connection count exceeds DB max_connections"

Check `SHOW STATUS LIKE 'Max_used_connections'` in MySQL. If it exceeds `max_connections`, connections are being refused.

Reduce `DB_POOL_SIZE` or increase MySQL's `max_connections`. Recalculate using the formula in the pool sizing section.

### EntityManager returns stale entities

After calling `flush()`, a subsequent `find()` for the same entity may return the pre-flush version from the identity map.

Call `$em->clear()` at the end of each request to reset the identity map. Add a `KernelEvents::TERMINATE` listener:

```php
final class DoctrineResetListener
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function onKernelTerminate(): void
    {
        $this->em->clear();
    }
}
```

Register it as a `kernel.event_listener` with `event: kernel.terminate`. This runs after the response is sent, clearing the identity map for the next request without affecting response time.
