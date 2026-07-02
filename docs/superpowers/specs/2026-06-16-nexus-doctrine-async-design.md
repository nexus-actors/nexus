# Nexus Doctrine Async Design

**Status:** Spec — pending review
**Author:** brainstorming session 2026-06-16
**Target branch:** `feat/nexus-doctrine` (cut from `feat/nexus-http`)
**Target packages:** `nexus-actors/doctrine-dbal`, `nexus-actors/doctrine-orm`

## Goal

First-class, coroutine-async Doctrine DBAL and ORM support for Nexus — usable from HTTP handlers, from generic actors, and from a new `EntityBehavior` DSL that treats a Doctrine entity as the state of a non-event-sourced aggregate actor. Provide per-thread connection pools and entity-manager pools that cooperate with Swoole and isolate cleanly across threads in `nexus-worker-pool-swoole`. Stock Doctrine drivers are used unchanged — async is delivered via Swoole's `SWOOLE_HOOK_ALL` coroutine hooks, not custom drivers.

## Non-goals

- True non-Swoole async (AmPHP MySQL/Postgres). Deferred until a non-Swoole production target appears.
- Multi-DB / read-replica routing. Instantiate two pools and pick — no built-in routing layer.
- Distributed transactions / two-phase commit.
- Deadlock-retry middleware. Trivial to add post-v1; deliberately deferred.
- Doctrine ORM second-level cache integration. Doctrine has its own.
- Migrations / schema diff tooling — `doctrine/migrations` exists.
- Multi-entity sagas via cross-entity `EntityBehavior`. V1 is single-entity-only.
- Modifying `nexus-persistence-dbal` / `nexus-persistence-doctrine`. Those keep doing event-sourcing / durable-state. The new packages are general-purpose data access.

## Architecture overview

### Packages

Two new packages, both off `feat/nexus-http` on a new `feat/nexus-doctrine` branch:

- `nexus-doctrine-dbal` — coroutine-aware `ConnectionPool`, `Connection` HTTP middleware + resolver, actor-side helpers, runtime bootstrap.
- `nexus-doctrine-orm` — `EntityManagerPool`, `PooledEntityManager` decorator, HTTP middleware + resolver, `EntityBehavior<T,C>` DSL + `EntityEffect`, `EntityRefFactory`.

### Dependency graph

Extends the existing graph in `CLAUDE.md`:

```
nexus-core
nexus-http  ─── nexus-doctrine-dbal ─── doctrine/dbal
                       │
                       └── nexus-doctrine-orm ─── doctrine/orm
```

`nexus-doctrine-dbal` depends only on `nexus-core` + `nexus-http` (for middleware contracts). `nexus-doctrine-orm` depends on `-dbal`. Enforced via Deptrac (`deptrac.yaml`).

### Runtime model

| Runtime | Behavior |
|---|---|
| `SwooleRuntime` (production) | `SWOOLE_HOOK_ALL` enabled via `DoctrineBootstrap::enable()`. Stock Doctrine PDO drivers become cooperatively async. Pool `take()` suspends the coroutine on a Swoole channel. |
| `nexus-worker-pool-swoole` threads | Each worker thread owns its own pools. Connections never cross thread boundaries (PDO resources are not ZTS-shareable). Total connections = `threads × pool_size`. |
| `FiberRuntime` (dev/test) | Stock blocking PDO. Pool API identical; `take()` blocks the fiber instead of yielding. Documented limitation; not a production target. |

`DoctrineBootstrap::enable()` is called once per worker thread inside `WorkerStartHandler::onWorkerStart()`. Required — without it, PDO blocks the entire coroutine scheduler.

## Connection pool (`nexus-doctrine-dbal`)

The pool is the single hot-path primitive. Callers borrow with `take()` and return with `release()`, or use `withConnection()` for safe-scope semantics. There is **no** decorator class on `Connection` — users get the real `Doctrine\DBAL\Connection`.

### Shape

```php
final class ConnectionPool
{
    public function __construct(
        ConnectionFactory $factory,
        PoolConfig $config,
        Channel $idle,        // Swoole\Coroutine\Channel under Swoole; in-memory deque under Fiber
    );

    public function take(?Duration $timeout = null): Connection;
    public function release(Connection $conn, bool $poison = false): void;
    public function withConnection(Closure $fn): mixed;        // try/finally helper
    public function close(Duration $timeout): void;
    public function stats(): PoolStats;
}
```

### Configuration

| Knob | Default | Purpose |
|---|---|---|
| `max` | 16 | Per-thread cap. |
| `minIdle` | 2 | Warm-pool baseline; avoids cold-start latency. |
| `borrowTimeout` | `Duration::seconds(5)` | Wait limit before `PoolExhaustedException`. |
| `idleTtl` | `Duration::minutes(5)` | Background evictor closes idle-too-long connections. |
| `acquireTtl` | `Duration::seconds(30)` | Warn on borrows held longer than this — leak detection. |
| `healthCheckOnBorrow` | `false` | `SELECT 1` on borrow if connection was idle past threshold. Off by default — costs 1 RTT. |
| `validationQuery` | `'SELECT 1'` | Driver-overridable. |

### Borrow / release semantics

- `take()` pops from the idle channel. If empty and `total < max`, lazily creates via the factory. If `total == max`, suspends the coroutine on the channel until either a release or `borrowTimeout`.
- `release($conn, poison: true)` destroys the connection rather than returning it. Slot is freed; next `take()` lazily creates a fresh one.
- **Poisoning rules:** SQL errors (`Driver\Exception`) do **not** poison — they're caller bugs, not connection bugs. Anything else (network, decode, etc.) caller may pass `poison: true`.
- `withConnection(Closure $fn)` runs `$fn($conn)` inside `try/finally`, poisoning on uncaught throw, otherwise returning cleanly.

### Background tasks (per pool, per thread)

1. **Evictor** — coroutine that wakes every `idleTtl/4`, closes idle-too-long connections, re-warms up to `minIdle`.
2. **Leak detector** — logs (PSR-3 `warning` + PSR-14 event) when a borrow exceeds `acquireTtl` without release.

### Public factory helper

```php
DoctrinePool::fromUrl('mysql://...', new PoolConfig(max: 32));
```

Most users never construct `ConnectionPool` directly.

## EntityManager pool (`nexus-doctrine-orm`)

A **sibling** to `ConnectionPool`, not a wrapper. Each pooled EM owns its own connection for its lifetime in the pool. Why sibling: an EM is built around a single `Connection` at construction time, and the UoW (identity map, scheduled writes) is tied to that EM. Swapping the connection under an EM is a bug.

Internally, `EntityManagerPool` still uses a private `ConnectionPool` — the pool primitive is reused, but user-visible budgets are independent (avoids cross-pool starvation).

### Shape

```php
final class EntityManagerPool
{
    public function __construct(
        EntityManagerFactory $factory,
        ConnectionPool $privateConnPool,
        EmPoolConfig $config,
        Channel $idle,
    );

    public function take(?Duration $timeout = null): PooledEntityManager;
    public function release(PooledEntityManager $em): void;
    public function withEntityManager(Closure $fn): mixed;
    public function close(Duration $timeout): void;
    public function stats(): EmPoolStats;
}
```

### `PooledEntityManager` — decorator

- Implements `EntityManagerInterface` by delegation. Callers see a normal EM.
- `release()` → `clear()` the UoW + push back to pool.
- If `$em->isOpen() === false` (Doctrine closes EM after flush failure), release destroys rather than returns; the pool builds a fresh one on next `take()`. Closed EM is poison.
- `clear()` on return drops the identity map so the next borrower starts clean — no leaked entity references across borrows.

### Configuration

| Knob | Default | Purpose |
|---|---|---|
| `max` | matches DBAL `max` | Per-thread cap. |
| `minIdle` | 2 | Warm baseline. |
| `borrowTimeout` | `Duration::seconds(5)` | As DBAL. |
| `clearOnReturn` | `true` | Off only for diagnostic scenarios. Leaking the identity map is almost always a bug. |
| `recreateAfter` | 1000 borrows | Periodic eviction to bound proxy-class cache growth. |

### Public factory helper

```php
DoctrineEmPool::forConfig(
    connConfig: ['url' => 'mysql://...'],
    ormSetup:   ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Entity']),
    config:     new EmPoolConfig(max: 32),
);
```

## HTTP integration

Strategy: per-request **lease** objects attached to the `ServerRequest` as attributes. Lazy borrow on first use inside the handler; guaranteed release in middleware `finally`. No handler ever calls `take()`/`release()` directly. No nullable parameters anywhere — DBAL and ORM each have an independent middleware.

### Per-resource leases

```php
final class ConnectionLease
{
    public function __construct(private ConnectionPool $pool);
    public function get(): Connection;          // lazy take
    public function poison(): void;
    public function release(): void;            // returns to pool, poisoning if flagged
}
```

`EntityManagerLease` mirrors the shape over `EntityManagerPool`.

### Middleware

```php
final class ConnectionScopeMiddleware implements MiddlewareInterface
{
    public function __construct(private ConnectionPool $pool) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $lease = new ConnectionLease($this->pool);
        $req = $req->withAttribute(ConnectionLease::class, $lease);
        try { return $next->handle($req); }
        catch (Throwable $e) { $lease->poison(); throw $e; }
        finally { $lease->release(); }
    }
}

final class EntityManagerScopeMiddleware implements MiddlewareInterface  // identical shape
```

App wires only what it uses:

```php
$app->use(new ConnectionScopeMiddleware($connPool));
$app->use(new EntityManagerScopeMiddleware($emPool));   // skip if no ORM
```

### Handler resolvers

- `nexus-doctrine-dbal/ConnectionResolver` — fires on parameter type `Doctrine\DBAL\Connection`. Reads `ConnectionLease` attribute from request, calls `->get()`. Throws `MissingConnectionScopeException` if the lease attribute is absent.
- `nexus-doctrine-orm/EntityManagerResolver` — same shape over `EntityManagerLease`.

Both resolvers register with the shared `ParamResolver` registry from the existing handler-resolver redesign spec (`2026-06-15-handler-resolver-redesign-design.md`). Handler signature is the API:

```php
public function __invoke(EntityManagerInterface $em): ResponseInterface
{
    $em->persist($order);
    $em->flush();
    return new JsonResponse(['id' => $order->getId()]);
}
```

Zero allocation if the handler never touches the DB.

### Transactions

**Attribute-driven:**

```php
#[Transactional]
final class CreateOrder { public function __invoke(EntityManagerInterface $em): Response { … } }
```

A route-level decorator reads `#[Transactional]` and wraps the handler in `$em->wrapInTransaction(fn() => $next($req))` (ORM path) or `$conn->transactional(...)` (DBAL path). Picks the EM if the handler declares one, otherwise the Connection. Throws `MissingTransactionalDependencyException` if neither is declared.

**Manual:** stock Doctrine `wrapInTransaction(...)` — always works.

### Pool exhaustion → 503

`PoolExhaustedException` bubbles up. `PoolExhaustedToServiceUnavailable` middleware maps it to HTTP 503 with `Retry-After: 1`. Other DB errors propagate to the app's existing error handler.

### Wiring helper

```php
DoctrineHttp::install($app, connPool: $connPool, emPool: $emPool);
// installs ConnectionScopeMiddleware, EntityManagerScopeMiddleware (only if emPool given),
// PoolExhaustedToServiceUnavailable, plus both resolvers.
```

## Actor-side access (non-`EntityBehavior` actors)

### Dependency-rule constraint

`nexus-core` must never depend on anything else (CLAUDE.md). So `ActorContext` does **not** grow `connection()`/`entityManager()` methods. All Doctrine plumbing lives in `nexus-doctrine-dbal` / `nexus-doctrine-orm`.

### Per-message borrow — the common case

Pool injected via `Props::fromFactory()` or `Props::fromContainer()`. Inside the handler, use `withConnection()` / `withEntityManager()`. The actor's single-threaded execution means the borrow is genuinely scoped to one message.

```php
$pool = $container->get(ConnectionPool::class);

$behavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use ($pool): Behavior {
    if ($msg instanceof LookupUser) {
        $row = $pool->withConnection(static fn(Connection $c) =>
            $c->fetchAssociative('SELECT * FROM users WHERE id = ?', [$msg->id]));
        $msg->replyTo->tell(new UserFound($row));
    }
    return Behavior::same();
});
```

### `ActorPoolBinding` helper

Optional ergonomic wrapper:

```php
final readonly class ActorPoolBinding
{
    public function __construct(public ConnectionPool $connPool, public ?EntityManagerPool $emPool = null);
}
```

Injected via `Props::fromFactory(fn() => $binding)`; handlers reference `$binding->connPool->...`. Power users skip it and inject pools directly.

### Long-lived borrow (rare, opt-in anti-pattern)

For actors that legitimately hold a connection for their whole life — e.g., a Postgres `LISTEN` subscriber:

```php
final class ListenerActor extends AbstractActor
{
    private ?Connection $conn = null;
    public function onPreStart(ActorContext $ctx): void { $this->conn = $this->pool->take(); }
    public function onPostStop(ActorContext $ctx): void { $this->pool->release($this->conn); }
    public function handle(ActorContext $ctx, object $message): Behavior { /* uses $this->conn */ }
}
```

Documented as **pool budget eater**. The Psalm `PooledConnectionInActorPropertyRule` (Section "Psalm plugin additions") warns on `Connection`/`EntityManagerInterface`-typed properties on `ActorHandler` implementations.

### Worker-pool integration

Each worker thread holds its own `ConnectionPool` and `EntityManagerPool`. Pools live as thread-local state, constructed in `WorkerStartHandler::onWorkerStart()` and torn down in the thread's shutdown hook. Actors spawned in a worker thread see only that thread's pools. `WorkerActorRef::tell()` keeps working as today — the receiving thread services any borrow on its own pool.

## `EntityBehavior` DSL (`nexus-doctrine-orm`)

The headline feature. Mirrors `DurableStateBehavior` so users who know that DSL pick this up immediately. Treats a Doctrine entity as the state of a non-event-sourced aggregate actor.

### Shape

```php
final readonly class EntityBehavior
{
    public static function create(
        string $entityClass,        // class-string<T>
        mixed $id,                  // entity identity
        Closure $commandHandler,    // fn(ActorContext<C>, C, T): EntityEffect<T>
    ): EntityBehaviorBuilder;
}
```

Closure signature is the only API surface user code touches:

```php
$handler = static function (ActorContext $ctx, object $cmd, Order $order): EntityEffect {
    return match (true) {
        $cmd instanceof AddLineItem => $order->tryAdd($cmd->sku, $cmd->qty)
            ? EntityEffect::persist()->thenReply($cmd->replyTo, fn(Order $o) => new LineAdded($o->total()))
            : EntityEffect::reply($cmd->replyTo, new LineRejected('out of stock')),
        $cmd instanceof Cancel       => EntityEffect::remove()
            ->thenReply($cmd->replyTo, fn(Order $o) => new OrderCancelled()),
        default                      => EntityEffect::same(),
    };
};
```

The entity exposes intent-returning methods (`tryAdd(): bool`) so the command handler stays pure-match-expression. `$order` is the live entity managed by the actor's dedicated EM — mutating it is the normal Doctrine pattern. `EntityEffect::persist()` is what triggers `$em->flush()`.

### `EntityEffect`

Strictly parallel to `DurableEffect`:

| Effect | Behavior |
|---|---|
| `EntityEffect::same()` | No DB op. UoW untouched. |
| `EntityEffect::persist()` | `$em->flush()`. UoW commits whatever you mutated. |
| `EntityEffect::remove()` | `$em->remove($entity); $em->flush()`; actor stops afterward. |
| `EntityEffect::stop()` | Actor stops. **No flush** — pending UoW changes are discarded. |
| `EntityEffect::reply($to, $msg)` | Send reply. Composable with persist/remove. |
| `EntityEffect::stash()` | Stash current message until `unstashAll()`. |

Composers: `->thenRun(fn(T $entity) => …)`, `->thenReply($to, fn(T $entity) => $msg)` — both fire **after** `flush()` so the entity has its post-write state (generated IDs, version bump).

### Loading and replay policy

On `PreStart`:

1. Open the dedicated EM (next section).
2. Run `EntityReplayPolicy::resolve($em, $entityClass, $id)`.

Policies:

- **`Fail` (default)** — `$em->find($entityClass, $id)` → throws `EntityNotFoundException` → `ActorInitializationException` → supervisor decides.
- **`CreateIfMissing(Closure $factory)`** — `find`, fall back to `$factory($id)` + `$em->persist($entity)`. Useful for "spawn on first access".
- **`OnDemand`** — skip load on start; defer until first command. Trades startup latency for cold-message latency. Useful for very-many-rarely-used entities.

### Dedicated, non-pooled EM (deliberate)

Each `EntityBehavior` actor gets a **dedicated EM constructed from `EntityManagerFactory`**, not from `EntityManagerPool`. Reasoning:

- Actor lifetime ≠ request lifetime. Hot entities run for minutes/hours. Pooling would either pin a pool slot forever (defeating the pool) or require swapping EMs (UoW tracks the entity by identity — swap = lose state).
- Connection is held for the actor's lifetime.

**Cost:** an app with 10k hot entity actors needs ≥10k connections. Mitigation patterns documented (not enforced): passivate idle entities (`onSignal(ReceiveTimeout)` + `Behavior::stopped()`), bound the active-actor count with a router.

Builder wiring:

```php
EntityBehavior::create(Order::class, $orderId, $handler)
    ->withEntityManagerFactory($emFactory)
    ->withReplayPolicy(EntityReplayPolicy::createIfMissing(fn($id) => new Order($id)))
    ->withLockMode(LockMode::OPTIMISTIC)
    ->withFlushMode(FlushMode::Commit)
    ->toBehavior();
```

### Single-writer guarantee via `EntityRefFactory`

Doctrine's identity map is single-writer within one EM. We extend that to the actor system: **at most one `EntityBehavior` actor per `(entityClass, id)` per node.**

```php
$orders = EntityRefFactory::for($system, Order::class)
    ->using($emFactory)
    ->withReplayPolicy(EntityReplayPolicy::createIfMissing(fn($id) => new Order($id)))
    ->handle($commandHandler)
    ->build();

$orders->of($orderId)->tell(new AddLineItem(...));
$orders->of($orderId)->ask(fn($replyTo) => new GetTotal($replyTo), Duration::seconds(2));
```

`of($id)` derives a deterministic actor name (`Order::42`), spawns once, returns a typed `ActorRef`. Subsequent calls return the same ref. In a `nexus-worker-pool-swoole` setup, this composes with the existing `ConsistentHashRing` — `(Order::42)` always routes to the same worker thread, so each entity has exactly one writer cluster-wide.

`#[MessageType]` attribute on commands is required for cross-worker delivery (existing Nexus rule, enforced by `NonSerializableRemoteMessageRule`).

### Optimistic locking and failure modes

- **Entity has `@Version`** → Doctrine raises `OptimisticLockException` on conflicting flush. Mapped by `EntityBehavior` to `EntityConflictException`. Default supervisor decider: **restart with reload** — `EM::close()` → new EM on restart → fresh `find()` → handler reprocesses the message. Configurable via `->withConflictStrategy(...)`.
- **EM closes after flush failure** (`$em->isOpen() === false`) — actor restart is mandatory; framework forces it regardless of supervision directive. Retry/backoff via standard `SupervisionStrategy`.
- **DB unavailable on `PreStart`** → `ActorInitializationException`. Supervision can use `exponentialBackoff(...)`.
- **Connection lost mid-message** → handler throws → message goes to dead letters → supervision triggers restart.

## Lifecycle, errors, observability

### Pool lifecycle

| Hook | Action |
|---|---|
| Worker thread start | `DoctrineBootstrap::enable()` → set `SWOOLE_HOOK_ALL`. Construct pools from thread-local config. Warm `minIdle` connections. |
| Worker thread stop | `$pool->close(Duration::seconds(30))` drains both pools: close idle resources, wait for in-flight borrows up to the timeout, force close. |
| Fiber dev path | Same API; `take()` blocks the fiber instead of yielding. `close()` synchronous. |

### Exception hierarchy

All new exceptions extend `NexusException` per CLAUDE.md convention:

- `PoolExhaustedException` — `take()` timed out. Carries pool name + stats. HTTP middleware maps to 503.
- `PoolClosedException` — `take()` after `close()`. Fatal.
- `ConnectionPoisonedException` — release-with-poison destroyed the connection; logged, not raised.
- `EntityConflictException` (ORM) — wraps `OptimisticLockException`.
- `MissingConnectionScopeException` / `MissingEntityManagerScopeException` — resolver couldn't find the lease attribute; "did you forget the scope middleware?".
- `MissingTransactionalDependencyException` — `#[Transactional]` on a handler with no Connection / EM parameter.

### Observability

PSR-14 events (`Monadial\Nexus\Doctrine\Event\…`):

- `ConnectionCreated`, `ConnectionDestroyed`, `ConnectionPoisoned`
- `ConnectionTaken(Duration $waitTime)`, `ConnectionReleased(Duration $heldFor)`
- `PoolExhausted(PoolStats)` — fires before the exception throws (distinct from real timeouts on dashboards)
- ORM parallels: `EntityManagerCreated`, `EntityManagerCleared`, `EntityManagerEvicted`

PSR-3 logger (from `ActorSystem`):

- `info` — pool warmup / shutdown
- `warning` — connection poisoning, leak detection, `EM::isOpen() === false`
- `debug` — every take / release (gated by flag, off by default)

`$pool->stats(): PoolStats { idle, inUse, total, waitingCoroutines, totalBorrows, totalWaits, totalTimeouts }` — read-only struct, safe to expose on `/health` or `/metrics`.

### Psalm plugin additions

Registered in `nexus-psalm` alongside the existing seven hooks:

1. **`PooledConnectionInActorPropertyRule`** — flags `Connection`/`EntityManagerInterface`-typed properties on `ActorHandler` implementations.
2. **`MissingTransactionalDeclarationRule`** — flags handlers using `#[Transactional]` with no Connection / EM parameter.
3. **`EntityBehaviorReturnTypeProvider`** — infers `EntityBehavior<T,C>` generic types from `create()` arguments so command-handler closure params type-check. Mirrors existing `Behavior::receive`/`withState`/`setup` inference hooks.

## Testing strategy

### Unit (per package)

- `ConnectionPoolTest` — take, release, poison, lazy create, max cap, timeout, eviction, leak detector, stats.
- `EntityManagerPoolTest` — same primitives, plus `clear()`-on-return verified via leaking-managed-entity assertions, `isOpen() === false` triggers eviction.
- `EntityEffectTest` — composition (`persist()` + `thenReply(...)` chains).
- `EntityBehaviorBuilderTest` — replay policy, lock mode, factory wiring.
- Psalm plugin tests under `nexus-psalm/tests/Unit/` following existing structure.

`ConnectionPool` uses a fake `ConnectionFactory` returning stub `Connection` objects — no real DB. Runs on `php-fiber` container.

### Integration (`tests/Integration/Doctrine/…`)

- `Fiber/` — pool + middleware + resolver against a real MySQL container. Verifies sync semantics.
- `Swoole/` — same suite under `SwooleRuntime` with `SWOOLE_HOOK_ALL`. Verifies cooperative borrow (concurrent coroutines waiting on the pool, pool-exhaustion timing).
- `WorkerPool/` — per-thread pool isolation, no cross-thread leakage.
- `EntityBehavior/` — happy path, replay policies, optimistic-lock conflict + reload restart, `EntityRefFactory::of()` returning same ref.

New compose service `mysql` (likely `mysql:8.4`) added to `docker-compose.yml` alongside existing `php` / `php-fiber` / `php-swoole`. `make test-doctrine` target added.

### Performance (`tests/Performance/Doctrine/`)

- Pool take/release throughput target: < 50 µs avg under Swoole with no contention.
- HTTP handler with `EntityManagerInterface` end-to-end RPS under `php-swoole` — define a DB-bound baseline (the wallet-app ~50k RPS baseline is non-DB).
- `EntityBehavior` `ask` throughput vs `DurableStateBehavior` for the same aggregate shape.

### Mutation

Infection runs as today (`make mutation`, MSI ≥ 80% / covered ≥ 90%). New packages included in `infection.json5`.

## Migration / rollout

- Branch: `git checkout -b feat/nexus-doctrine` from current `feat/nexus-http` HEAD.
- `feat/nexus-http` stays frozen.
- Both new packages ship together (DBAL + ORM in one delivery). EntityBehavior depends on ORM, so DBAL-only is not a useful intermediate.
- All 15 existing `packages/*/composer.json` files unchanged (no cross-package dev-dep version bumps).
- Root `composer.json` adds `doctrine/dbal: ^4.0` and `doctrine/orm: ^3.0` to `require-dev` (already present transitively via `nexus-persistence-dbal` / `nexus-persistence-doctrine`).
- Each new package gets its own `composer.json` published independently per existing monorepo convention.

## Open questions (resolved during brainstorming)

| Question | Decision |
|---|---|
| Async strategy | Swoole `SWOOLE_HOOK_ALL` coroutine hooks. Stock Doctrine drivers, no custom drivers. |
| Pool topology | Per-thread pool. No cross-thread sharing. |
| Entity-as-actor-state | `EntityBehavior` DSL mirroring `DurableStateBehavior`. |
| Scope | DBAL + ORM together. Two new packages. |
| Branch | New `feat/nexus-doctrine` off `feat/nexus-http`. |
| Pool API shape | `take()` / `release()` + `withConnection()` sugar. No `PooledConnection` decorator (real `Doctrine\DBAL\Connection` exposed). |
| Middleware structure | Two focused middlewares (`ConnectionScopeMiddleware`, `EntityManagerScopeMiddleware`) — no shared scope object, no nullable params. |
| Pooled EM | `EntityManagerPool` as sibling primitive; `PooledEntityManager` decorator implementing `EntityManagerInterface`. |
| EM for `EntityBehavior` | Dedicated, non-pooled (from `EntityManagerFactory`). |
