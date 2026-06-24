---
title: Configuration reference
related:
  - core-concepts/mailboxes
  - core-concepts/supervision
  - reference/system-messages
  - scaling/choosing-thread-count
---

# Configuration reference

This page documents every configuration value object in Nexus: `MailboxConfig`, `OverflowStrategy`, `SupervisionStrategy`, `WorkerPoolConfig`, and `SwooleConfig`.

## MailboxConfig

`MailboxConfig` is an immutable value object that controls the capacity and overflow policy of an actor's mailbox. Pass it to `Props::withMailbox()` before spawning an actor.

```php title="src/Actor/OrderActor.php"
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

$props = Props::fromBehavior($behavior)
    ->withMailbox(MailboxConfig::bounded(500, OverflowStrategy::Backpressure));
```

### Named constructors

| Method | Parameters | Description |
|---|---|---|
| `MailboxConfig::bounded(int $capacity, OverflowStrategy $strategy)` | `$capacity` — maximum queue depth; `$strategy` — what happens when full | Create a bounded mailbox. Default strategy: `ThrowException`. |
| `MailboxConfig::unbounded()` | — | Create an unbounded mailbox. Queue grows without limit. |

### Modifier methods

| Method | Returns | Description |
|---|---|---|
| `withCapacity(int $capacity)` | `MailboxConfig` | Return a new config with the given capacity. |
| `withStrategy(OverflowStrategy $strategy)` | `MailboxConfig` | Return a new config with the given overflow strategy. |

### Parameters

| Property | Type | Description |
|---|---|---|
| `$capacity` | `int` | Maximum number of enqueued messages. `PHP_INT_MAX` for unbounded. |
| `$strategy` | `OverflowStrategy` | Policy applied when the mailbox is full (bounded only). |
| `$bounded` | `bool` | `true` for bounded; `false` for unbounded. |

---

## OverflowStrategy

`OverflowStrategy` is a backed enum that controls what happens when a bounded mailbox is at capacity and a new message arrives.

| Case | Value | Behaviour |
|---|---|---|
| `DropNewest` | `drop_newest` | Silently discard the incoming message. The mailbox keeps all existing messages. |
| `DropOldest` | `drop_oldest` | Silently discard the oldest message in the queue to make room. |
| `Backpressure` | `backpressure` | Suspend the sending fiber until space is available. |
| `ThrowException` | `throw_exception` | Throw `MailboxOverflowException` at the call site of `tell()`. Default. |

```php title="src/Actor/Pipeline.php"
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

// Circuit-breaker: reject excess messages immediately
$props = Props::fromBehavior($behavior)
    ->withMailbox(MailboxConfig::bounded(200, OverflowStrategy::ThrowException));

// Rate-limit producers: block sender fiber until mailbox drains
$props = Props::fromBehavior($behavior)
    ->withMailbox(MailboxConfig::bounded(200, OverflowStrategy::Backpressure));
```

:::caution Backpressure holds the sender's fiber
`OverflowStrategy::Backpressure` suspends the calling fiber until the mailbox has room. If the mailbox never drains, the sender hangs indefinitely. Apply a send timeout at the application level when using this strategy in pipelines.
:::

---

## SupervisionStrategy

`SupervisionStrategy` is an immutable value object attached to `Props` via `Props::withSupervision()`. It determines what the parent actor does when a child throws an unhandled exception.

The decider is a `Closure(Throwable): Directive` that maps each exception type to one of four directives: `Directive::Restart`, `Directive::Stop`, `Directive::Resume`, or `Directive::Escalate`.

### SupervisionStrategy::oneForOne

Only the failed child is acted upon. Other children continue processing.

```php title="src/Actor/OrderSupervisor.php"
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Supervision\Directive;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Duration;

$props = Props::fromBehavior($behavior)->withSupervision(
    SupervisionStrategy::oneForOne(
        maxRetries: 5,
        window: Duration::seconds(30),
        decider: static fn(\Throwable $e): Directive => $e instanceof \RuntimeException
            ? Directive::Restart
            : Directive::Stop,
    ),
);
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$maxRetries` | `int` | `3` | Maximum restart attempts within `$window` before the child is stopped. |
| `$window` | `Duration\|null` | `Duration::seconds(60)` | Rolling time window for counting restarts. |
| `$decider` | `Closure(Throwable): Directive\|null` | `Directive::Restart` for all | Maps each exception to a directive. |

### SupervisionStrategy::allForOne

When one child fails, all children are acted upon by the same directive.

```php title="src/Actor/ClusterSupervisor.php"
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Duration;

$strategy = SupervisionStrategy::allForOne(
    maxRetries: 3,
    window: Duration::seconds(60),
);
```

Parameters are identical to `oneForOne`.

### SupervisionStrategy::exponentialBackoff

Restarts the failed child with increasing delay between attempts. Use when the failure is likely transient and immediate restart would cause a thundering-herd problem.

```php title="src/Actor/DbActor.php"
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Duration;

$strategy = SupervisionStrategy::exponentialBackoff(
    initialBackoff: Duration::millis(100),
    maxBackoff: Duration::seconds(30),
    maxRetries: 10,
    multiplier: 2.0,
);
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$initialBackoff` | `Duration` | — | Delay before the first restart attempt. |
| `$maxBackoff` | `Duration` | — | Upper bound on the delay; subsequent attempts are capped here. |
| `$maxRetries` | `int` | `3` | Maximum number of restart attempts total. |
| `$multiplier` | `float` | `2.0` | Factor applied to `$initialBackoff` on each retry. |
| `$decider` | `Closure(Throwable): Directive\|null` | `Directive::Restart` for all | Maps each exception to a directive. |

---

## WorkerPoolConfig

`WorkerPoolConfig` configures the Swoole thread pool used by the worker-pool package. Call `WorkerPoolApp::run(WorkerPoolConfig)` with this value.

```php title="src/WorkerPool/MyPoolApp.php"
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

WorkerPoolConfig::withThreads(8);
```

| Method | Parameter | Description |
|---|---|---|
| `WorkerPoolConfig::withThreads(int $workerCount)` | `$workerCount` ≥ 1 | Create a config for N worker threads. Throws `InvalidArgumentException` if `$workerCount < 1`. |
| `withSystemNamePrefix(string $prefix)` | Any non-empty string | Override the default `'worker'` prefix used for internal actor system names. |

---

## SwooleConfig

`SwooleConfig` tunes the Swoole runtime. Pass it to `SwooleRuntime::__construct()`.

```php title="src/bootstrap.php"
use Monadial\Nexus\Runtime\Swoole\SwooleConfig;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

$runtime = new SwooleRuntime(
    new SwooleConfig(
        defaultMailboxCapacity: 5000,
        enableCoroutineHook: true,
        maxCoroutines: 200_000,
    ),
);
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$defaultMailboxCapacity` | `int` | `1000` | Default channel size for actor mailboxes created by the Swoole runtime. |
| `$enableCoroutineHook` | `bool` | `true` | Enable Swoole's coroutine hooks to make blocking I/O non-blocking inside coroutines. |
| `$maxCoroutines` | `int` | `100_000` | Maximum concurrent coroutines allowed in the Swoole event loop. |

### Modifier methods

| Method | Description |
|---|---|
| `withDefaultMailboxCapacity(int $capacity)` | Return a new config with the given mailbox capacity. |
| `withEnableCoroutineHook(bool $enable)` | Return a new config with coroutine hooking on or off. |
| `withMaxCoroutines(int $max)` | Return a new config with the given coroutine ceiling. |

---

## See also

- [Mailboxes](../core-concepts/mailboxes.md) — how mailboxes enqueue, dequeue, and backpressure
- [Supervision](../core-concepts/supervision.md) — the supervision model and directive semantics
- [Choosing thread count](../scaling/choosing-thread-count.md) — how to pick `WorkerPoolConfig::withThreads(N)`
- [Overflow strategies](./exceptions.md#mailboxoverflowexception) — when `OverflowStrategy::ThrowException` fires
