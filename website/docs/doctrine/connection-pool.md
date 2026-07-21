---
sidebar_position: 2
title: Connection Pool
related:
  - doctrine/overview
  - doctrine/entity-manager-pool
  - doctrine/http-integration
  - doctrine/entity-behavior
---

# Connection Pool

`ConnectionPool` is the primitive everything else borrows through. It is a per-thread, coroutine-aware pool of Doctrine DBAL `Connection` instances with lazy creation, idle eviction, leak detection, and cooperative wait semantics.

## Quick start

```php title="src/Bootstrap/WorkerStart.php"
use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Runtime\Duration;

// Once per worker thread:
DoctrineBootstrap::enable();

$pool = DoctrinePool::fromParams(
    name: 'orders',
    connParams: ['driver' => 'pdo_mysql', 'url' => 'mysql://app:secret@db/orders'],
    config: new PoolConfig(max: 32, minIdle: 4, borrowTimeout: Duration::seconds(2)),
);

// Use it:
$total = $pool->withConnection(static fn($conn): int =>
    (int) $conn->fetchOne('SELECT COUNT(*) FROM orders'));

// On worker shutdown:
$pool->close(Duration::seconds(30));
```

## `DoctrineBootstrap::enable()`

Idempotent. Calls `Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` when Swoole is loaded; no-op otherwise. After this call, stock Doctrine PDO drivers become cooperatively async — every blocking I/O suspends the current coroutine instead of blocking the whole worker.

`isEnabled()` returns `true` only when the hooks are actually installed, i.e. only under Swoole. Use this to assert the runtime is wired in your worker bootstrap if you want to fail-fast.

## `PoolConfig`

| Knob | Default | Purpose |
|---|---|---|
| `max` | `16` | Per-thread cap. With 8 worker threads that is 128 connections — a sane MySQL default. |
| `minIdle` | `2` | Warm baseline — avoids cold-start latency on first request. |
| `borrowTimeout` | `Duration::seconds(5)` | Cooperative wait when at-cap; throws `PoolExhaustedException` on expiry. |
| `idleTtl` | `Duration::seconds(300)` | Evictor closes connections idle longer than this. |
| `acquireTtl` | `Duration::seconds(30)` | Warn on borrows held longer than this — leak detection. |
| `healthCheckOnBorrow` | `false` | `SELECT 1` on borrow if the connection has been idle past threshold. Costs one RTT. |
| `validationQuery` | `'SELECT 1'` | Driver-overridable validation query. |
| `resetQuery` | `null` | SQL run on every release to clear per-session state (roles, `search_path`, `SET` vars, temp tables, advisory locks) before reuse — e.g. `'DISCARD ALL'` or `'RESET ALL'` on PostgreSQL. **Required for multi-tenant or session-role deployments.** An active transaction is always rolled back regardless. |

Validation rules: `max > 0`, `minIdle <= max`.

## Borrow semantics

### `take(?Duration $timeout = null): Connection`

Returns a real `Doctrine\DBAL\Connection` — no decorator. Resolution order:

1. Pop an existing connection from the idle channel — instant.
2. If `total < max`, lazily create via the factory — fast (one TCP/TLS handshake, then a real PDO instance).
3. Otherwise, suspend the coroutine on the channel until either a release arrives or `borrowTimeout` elapses.

Throws `PoolClosedException` if the pool has been closed. Throws `PoolExhaustedException` (carrying current `PoolStats`) when no connection is available within `borrowTimeout`. The HTTP middleware maps `PoolExhaustedException` to a 503 response.

### `release(Connection $conn, bool $poison = false): void`

Sanitizes and returns the connection to the idle channel. Before requeue, `release()` **rolls back any active transaction unconditionally** and, if `resetQuery` is configured, runs it to clear per-session state — so the next borrower (possibly a different tenant or request) can never inherit an open transaction, session role, `search_path`, `SET` variables, temp tables, or advisory locks. If that cleanup itself fails, the connection is **poisoned** (destroyed) rather than requeued with dirty state.

If `poison: true`, the connection is destroyed without sanitizing — the next `take()` creates a fresh one. Use `poison: true` when you observe a network or protocol error that may have left the connection in a corrupt state.

### Multi-tenant deployments

The pool is keyed by its `name`. When different trust domains (tenants, roles) share a database server, either give each tenant its **own** named pool, or set `resetQuery` (e.g. `'DISCARD ALL'`) so a connection is fully reset between borrows. Without one of these, session-scoped state set by one tenant can be observed by the next — the transaction rollback alone does not clear roles or `search_path`. The `nexus-doctrine-orm` `EntityManagerPool` applies the same transaction-rollback-on-release and clears the identity map on reuse (`clearOnReturn`).

SQL errors do not poison. A unique-constraint violation or syntax error is a caller bug, not a connection bug; the connection itself is still healthy. The HTTP `ConnectionScopeMiddleware` only poisons on `Doctrine\DBAL\Exception` (the documented root for connection-state-corrupting errors). Domain and HTTP exceptions (validation, 404s) release the connection back to the pool intact, so a single buggy handler cannot shrink the pool.

### `withConnection(Closure $fn): mixed`

Sugar for the common case:

```php title="src/Repository/OrderRepository.php"
public function withConnection(Closure $fn): mixed
{
    $conn = $this->take();
    $poison = false;
    try {
        return $fn($conn);
    } catch (Throwable $e) {
        $poison = true;
        throw $e;
    } finally {
        $this->release($conn, poison: $poison);
    }
}
```

Returns whatever the closure returns. The connection is released even on exception, and poisoned if the exception propagates out.

### `close(Duration $timeout): void`

Marks the pool closed (subsequent `take()` calls throw `PoolClosedException`), drains the idle channel, and destroys remaining in-flight connections after they are returned.

## Background tasks

Two coroutines per pool, started on first use:

- **Evictor** — wakes every `idleTtl/4`, closes connections idle longer than `idleTtl`, re-warms back to `minIdle`.
- **Leak detector** — periodically scans in-use entries; logs a PSR-3 warning when a borrow exceeds `acquireTtl`. Useful for catching forgotten `release()` calls.

## Observability

### `stats(): PoolStats`

Read-only snapshot:

```php title="src/Http/Handler/HealthCheck.php"
$stats = $pool->stats();
// $stats->idle, ->inUse, ->total, ->waitingCoroutines,
// $stats->totalBorrows, ->totalWaits, ->totalTimeouts
```

Safe to expose on a `/metrics` or `/health` endpoint.

### PSR-14 events

Pass a `Psr\EventDispatcher\EventDispatcherInterface` to the pool constructor (or `DoctrinePool::fromParams(events: $dispatcher, ...)`). Events emitted:

- `ConnectionCreated`, `ConnectionDestroyed`, `ConnectionPoisoned`
- `ConnectionTaken(Duration $waitTime)`
- `ConnectionReleased(Duration $heldFor)`
- `PoolExhausted(PoolStats $stats)` — fires before the exception throws, so dashboards can distinguish waits-that-timed-out from real exhaustion failures.

### PSR-3 logging

Pass a `Psr\Log\LoggerInterface`. Output:

- `info` — warmup and shutdown
- `warning` — leak detection, failed clean-close

## Worker-thread integration

In `nexus-worker-pool-swoole`, each worker thread constructs its own pool inside `WorkerStartHandler::onWorkerStart()`:

```php title="src/Bootstrap/AppStart.php"
use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;

final class AppStart implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node, ActorSystem $system): void
    {
        DoctrineBootstrap::enable();

        $pool = DoctrinePool::fromParams(
            name: 'orders',
            connParams: ['driver' => 'pdo_mysql', 'url' => '...'],
            config: new PoolConfig(max: 16, minIdle: 4),
        );

        $node->set(ConnectionPool::class, $pool);
    }
}
```

Pools never cross thread boundaries — PDO resources are not ZTS-shareable. Total connections = `threads × pool.max`.

## Exceptions

| Exception | Thrown by | HTTP mapping |
|---|---|---|
| `PoolExhaustedException` | `take()` | 503 with `Retry-After: 1` (via `PoolExhaustedToServiceUnavailable` middleware) |
| `PoolClosedException` | `take()` after `close()` | propagates |
| `ConnectionPoisonedException` | internal (logged, not raised) | — |
| `MissingConnectionScopeException` | `ConnectionResolver` when middleware not installed | propagates |

All extend `Monadial\Nexus\Core\Exception\NexusException`.

## See also

- [HTTP Integration](./http-integration.md) — request-scoped connection leases and `#[Transactional]`
- [EntityManager Pool](./entity-manager-pool.md) — ORM pool built on top of `ConnectionPool`
- [Doctrine Overview](./overview.md) — choosing between pool injection and `EntityBehavior`
