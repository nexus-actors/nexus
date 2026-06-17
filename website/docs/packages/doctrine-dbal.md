---
sidebar_position: 15
title: nexus-doctrine-dbal
---

# nexus-doctrine-dbal

Coroutine-aware Doctrine DBAL `ConnectionPool` with HTTP integration and
actor-side helpers. Production-ready under Swoole; falls back to plain
sync PDO under Fiber for dev/tests.

**Composer:** `nexus-actors/doctrine-dbal`

**Namespace:** `Monadial\Nexus\Doctrine\Dbal\`

**Dependencies:** `doctrine/dbal ^4.0`, `nexus-actors/core`,
`nexus-actors/http`, `nexus-actors/runtime`, PSR-3, PSR-7, PSR-14, PSR-15,
PSR-17.

This package is **separate** from `nexus-persistence-dbal` (the
event-sourcing/durable-state stores). You can use both — they coexist
cleanly.

## At a glance

- `ConnectionPool` — per-thread, lazy, with cooperative wait under Swoole
- `Channel` abstraction — `SwooleChannel` + `FiberChannel`
- `ConnectionScopeMiddleware` + `ConnectionResolver` — handler injection
- `#[Transactional]` + `TransactionalDecorator` — auto-commit/rollback
- `DoctrineBootstrap::enable()` — sets `SWOOLE_HOOK_ALL`
- `ActorPoolBinding` — inject pool into actors via `Props::fromFactory()`
- `PoolExhaustedToServiceUnavailable` — exhaustion → 503 mapping

See [Doctrine / Overview](../doctrine/overview.md) for the conceptual
guide and [Doctrine / Connection Pool](../doctrine/connection-pool.md) for
the full configuration surface.

## Classes

### Pool

| Class | Description |
|---|---|
| `Pool\ConnectionPool` | The pool primitive. `take()`, `release()`, `withConnection()`, `close()`, `stats()`. |
| `Pool\PoolConfig` | `max`, `minIdle`, `borrowTimeout`, `idleTtl`, `acquireTtl`, `healthCheckOnBorrow`, `validationQuery`. Named-only constructor. |
| `Pool\PoolStats` | Read-only snapshot: `idle`, `inUse`, `total`, `waitingCoroutines`, `totalBorrows`, `totalWaits`, `totalTimeouts`. |
| `Pool\ConnectionFactory` | Interface: `create(): Connection`. |
| `Pool\DriverManagerConnectionFactory` | Default impl wrapping `DriverManager::getConnection($params)`. |
| `Pool\Evictor` | Closes idle connections older than `idleTtl`, re-warms to `minIdle`. Run on a Swoole coroutine timer. |
| `Pool\LeakDetector` | Logs PSR-3 warnings on borrows older than `acquireTtl`. |

### Channels

| Class | Description |
|---|---|
| `Pool\Channel\Channel` | Interface: bounded queue with optional blocking `pop`. |
| `Pool\Channel\SwooleChannel` | Backed by `Swoole\Coroutine\Channel`. `pop()` suspends the coroutine. |
| `Pool\Channel\FiberChannel` | Backed by `SplQueue`. `pop()` non-blocking (PDO blocks the fiber anyway). |

### Bootstrap + facade

| Class | Description |
|---|---|
| `Bootstrap\DoctrineBootstrap` | `enable()` sets `SWOOLE_HOOK_ALL` when Swoole is loaded. `isEnabled()` returns true only when hooks are actually active. Idempotent. |
| `DoctrinePool` | Static facade `fromParams(name, connParams, config?, events?, logger?)` wires `ConnectionPool` + factory + channel + bootstrap. |

### HTTP integration

| Class | Description |
|---|---|
| `Http\ConnectionLease` | Per-request lease. `get(): Connection` (lazy), `poison()`, `release()`. |
| `Http\ConnectionScopeMiddleware` | PSR-15. Attaches `ConnectionLease` to request; releases on response; poisons on uncaught throw. |
| `Http\ConnectionResolver` | `ParamResolver` impl matching `Doctrine\DBAL\Connection`. |
| `Http\Attribute\Transactional` | `#[Attribute(TARGET_CLASS \| TARGET_METHOD)]`. Marker, no fields. |
| `Http\TransactionalDecorator` | Wraps a `RequestHandlerInterface` in `beginTransaction`/`commit`/`rollBack`. |
| `Http\PoolExhaustedToServiceUnavailable` | Maps `PoolExhaustedException` to 503 + `Retry-After`. |
| `Http\DoctrineHttp` | Static facade `install(registry, &middlewares, connPool, responseFactory?)`. Returns the new immutable registry. |

### Actor integration

| Class | Description |
|---|---|
| `Actor\ActorPoolBinding` | `final readonly` carrier — `public ConnectionPool $connPool`. Inject via `Props::fromFactory()`. |

### Events + exceptions

| Class | Notes |
|---|---|
| `Event\ConnectionCreated`, `ConnectionDestroyed`, `ConnectionPoisoned` | Lifecycle. |
| `Event\ConnectionTaken(poolName, Duration $waitTime)` | Wait time = how long `take()` blocked before getting a connection. |
| `Event\ConnectionReleased(poolName, Duration $heldFor)` | |
| `Event\PoolExhausted(poolName, PoolStats $stats)` | Fired before the exception throws. |
| `Exception\PoolExhaustedException::after(name, stats)` | `take()` timed out. |
| `Exception\PoolClosedException` | `take()` after `close()`. |
| `Exception\ConnectionPoisonedException` | Logged, not raised to callers. |
| `Exception\MissingConnectionScopeException` | `ConnectionResolver` when middleware not installed. |
| `Exception\MissingTransactionalDependencyException` | Reserved for future use; currently informational. |

All exceptions extend `Monadial\Nexus\Core\Exception\NexusException`.

## Wiring example

Inside a `WorkerStartHandler`:

```php
use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Http\DoctrineHttp;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;

DoctrineBootstrap::enable();

$pool = DoctrinePool::fromParams(
    name: 'orders',
    connParams: ['driver' => 'pdo_mysql', 'url' => $_ENV['DATABASE_URL']],
    config: new PoolConfig(max: 16, minIdle: 2),
);

$registry = DoctrineHttp::install(
    registry: new ParamResolverRegistry(),
    middlewares: $middlewares,
    connPool: $pool,
);
```

Handler:

```php
final class GetOrderCount
{
    public function __invoke(
        ServerRequestInterface $req,
        Connection $conn,
    ): ResponseInterface {
        return new JsonResponse(['count' => (int) $conn->fetchOne('SELECT COUNT(*) FROM orders')]);
    }
}
```
