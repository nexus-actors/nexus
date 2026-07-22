---
title: Configuration reference
related:
  - core-concepts/mailboxes
  - core-concepts/supervision
  - reference/system-messages
  - scaling/choosing-thread-count
---

# Configuration reference

This page documents every configuration value object in Nexus: `MailboxConfig`, `OverflowStrategy`, `SupervisionStrategy`, `WorkerPoolConfig`, `SwooleConfig`, `ReceiverActorConfig`, `LifecycleThresholds`, `ReplyQueueLifecycle`, and `AskSupport`.

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

## ReceiverActorConfig

`ReceiverActorConfig` is an immutable value object that tunes the `ReceiverActor` poll loop. Obtain the default with `ReceiverActorConfig::default()` and chain wither methods for overrides.

```php title="src/bootstrap.php"
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;
use Monadial\Nexus\Runtime\Duration;

$config = ReceiverActorConfig::default()
    ->withPollInterval(Duration::millis(50))
    ->withUnroutablePolicy(UnroutablePolicy::DeadLetters);
```

### Named constructors

| Method | Description |
|---|---|
| `ReceiverActorConfig::default()` | `pollInterval` = 100 ms, `unroutablePolicy` = `Reject`, `askPendingTimeout` = 30 s, `maxPendingAsks` = 1024. |

### Modifier methods

| Method | Returns | Description |
|---|---|---|
| `withPollInterval(Duration $pollInterval)` | `ReceiverActorConfig` | Override how long the actor waits before the next poll when idle or backpressured. |
| `withUnroutablePolicy(UnroutablePolicy $policy)` | `ReceiverActorConfig` | Override what happens to messages that `MessageRouter::route()` returns `null` for. |
| `withAskPendingTimeout(Duration $timeout)` | `ReceiverActorConfig` | Override the deadline after which an un-answered ask envelope is rejected for redelivery. |
| `withMaxPendingAsks(int $max)` | `ReceiverActorConfig` | Override the cap on concurrently pending asks. Must be a positive integer (throws `InvalidArgumentException` otherwise). |

### Parameters

| Property | Type | Default | Description |
|---|---|---|---|
| `$pollInterval` | `Duration` | `Duration::millis(100)` | Wait between idle or backpressured poll ticks. Busy ticks re-poll immediately. |
| `$unroutablePolicy` | `UnroutablePolicy` | `UnroutablePolicy::Reject` | `Reject` — reject back to transport; `DeadLetters` — forward to the dead-letters ref and ack. |
| `$askPendingTimeout` | `Duration` | `Duration::seconds(30)` | How long the receiver holds a broker envelope un-acked while waiting for the responder actor to publish a reply. When the deadline passes, the envelope is rejected for redelivery and `nexus.messenger.asks.responder_expired` is incremented. |
| `$maxPendingAsks` | `int` | `1024` | Cap on the number of unanswered ask envelopes held in memory at once. Once reached, a new ask is shed — rejected for broker redelivery instead of being tracked — and `nexus.messenger.asks.shed` is incremented, so a producer flooding asks cannot grow the pending map without bound. The live pending count is exposed as the `nexus.messenger.asks.pending` gauge. |

---

## ReplyQueueLifecycle

`ReplyQueueLifecycle` is a pure enum that controls how the reply queue behind a `TransportReplyChannelFactory` is created and torn down.

| Case | Queue created by Nexus | Queue torn down by Nexus | Notes |
|---|---|---|---|
| `Ephemeral` | Yes — on the first ask call | No — left to the broker (TTL / auto-delete) | **Default.** Requires the broker to support per-queue TTL or auto-delete. |
| `DeleteOnShutdown` | Yes — on the first ask call | Best-effort via `reset()` on `AskSupport::close()` | Broker-side TTL is the authoritative backstop; `reset()` only resets connection state, it does not delete the queue. |
| `Persistent` | No — externally pre-provisioned | Never | Use for SQS and brokers where queue creation is slow or costly. **Warning:** all instances that share the queue name compete for replies. Use only one consumer per channel name. The DSN template must not contain `{instance}`. |

```php
use Monadial\Nexus\Messenger\Ask\ReplyQueueLifecycle;
use Monadial\Nexus\Messenger\Ask\TransportReplyChannelFactory;

$factory = new TransportReplyChannelFactory(
    $transportFactory,
    $serializer,
    'amqp://broker/replies-{name}-{instance}?queue[ttl]=300000',
    'orders-replies',
    ReplyQueueLifecycle::Ephemeral,  // or DeleteOnShutdown, Persistent
);
```

---

## AskSupport

`AskSupport` coordinates broker ask/reply on the asker side: it lazily creates the reply channel, spawns the `nexus-ask-replies` consumer actor, registers pending ask futures, and schedules timeouts. The idiomatic way to build one is `MessengerBridge::askSupport()`.

```php title="src/bootstrap.php"
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Runtime\Duration;

$askSupport = MessengerBridge::askSupport(
    system: $system,
    factory: $channelFactory,
    maxPending: 5_000,                     // optional; default 10 000
    replyPollInterval: Duration::millis(10), // optional; default 20 ms
    observability: $observability,          // optional; default NoopObservability
    events: $eventDispatcher,               // optional; default null
);

$ref = MessengerBridge::producer($transport, 'orders-out', askSupport: $askSupport);
```

### MessengerBridge::askSupport() parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$system` | `ActorSystem` | — | The actor system; used to spawn the `nexus-ask-replies` consumer and schedule timeouts. |
| `$factory` | `ReplyChannelFactory` | — | Factory that creates the reply transport channel. Typically a `TransportReplyChannelFactory`. |
| `$maxPending` | `?int` | `10 000` | Maximum number of concurrent in-flight asks. When reached, `ask()` throws `AskCapacityExceededException` immediately. |
| `$replyPollInterval` | `?Duration` | `Duration::millis(20)` | How often the `ReplyConsumer` actor polls the reply channel when idle. Busy ticks (replies found) re-poll immediately. |
| `$observability` | `Observability` | `NoopObservability` | OTel instrumentation for ask metrics and spans. |
| `$events` | `?EventDispatcherInterface` | `null` | PSR-14 dispatcher for `AskStarted`, `AskResolved`, `AskTimedOut`, and `ReplyPublished` events. |

### AskSupport methods

| Method | Description |
|---|---|
| `replyChannelName(): string` | Return the logical reply channel name, lazily creating the channel and spawning the consumer actor on the first call. Idempotent. |
| `ask(Duration $timeout, string $correlationId): Future` | Register a pending ask and schedule its timeout. Throws `AskCapacityExceededException` at capacity. |
| `registry(): PendingAskRegistry` | Access the underlying pending-ask registry (for monitoring or testing). |
| `close(): void` | Release the reply channel. Call during `ActorSystem` shutdown to release transport resources. |

---

## LifecycleThresholds

`LifecycleThresholds` is an immutable value object evaluated by `LifecycleWatchdog` on each tick. A `null` limit is disabled. All comparisons are inclusive: reaching the limit exactly triggers a breach.

```php title="src/bootstrap.php"
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Runtime\Duration;

$thresholds = LifecycleThresholds::none()
    ->withMessageLimit(10_000)
    ->withMemoryLimit(128 * 1024 * 1024)
    ->withTimeLimit(Duration::seconds(3600));
```

### Named constructors

| Method | Description |
|---|---|
| `LifecycleThresholds::none()` | All three limits disabled (`null`). |

### Modifier methods

| Method | Parameter | Description |
|---|---|---|
| `withMemoryLimit(int $bytes)` | Bytes | Breach when `memory_get_usage(true) >= $bytes`. |
| `withMessageLimit(int $count)` | Count | Breach when cumulative processed messages `>= $count`. |
| `withTimeLimit(Duration $limit)` | Duration | Breach when actor uptime `>=` the limit (second precision). |

### Parameters

| Property | Type | Default | Description |
|---|---|---|---|
| `$memoryLimitBytes` | `?int` | `null` | Memory threshold in bytes; `null` = disabled. |
| `$messageLimit` | `?int` | `null` | Cumulative message count threshold; `null` = disabled. |
| `$timeLimit` | `?Duration` | `null` | Uptime threshold; `null` = disabled. |

### LifecycleWatchdog defaults

When `LifecycleWatchdog::create()` is called without explicit timing parameters, the watchdog uses:

| Parameter | Default |
|---|---|
| `$checkInterval` | `Duration::seconds(5)` |
| `$shutdownTimeout` | `Duration::seconds(10)` |

---

## See also

- [Mailboxes](../core-concepts/mailboxes.md) — how mailboxes enqueue, dequeue, and backpressure
- [Supervision](../core-concepts/supervision.md) — the supervision model and directive semantics
- [Choosing thread count](../scaling/choosing-thread-count.md) — how to pick `WorkerPoolConfig::withThreads(N)`
- [Overflow strategies](./exceptions.md#mailboxoverflowexception) — when `OverflowStrategy::ThrowException` fires
