---
sidebar_position: 9
title: Observability
related:
  - best-practices/testing
  - best-practices/scaling
  - core-concepts/lifecycle
  - core-concepts/actors
---

# Observability

This page covers what to measure, log, and watch in a running actor system, and how to wire those signals to the tools you already use.

## The three layers

Effective observability for an actor system requires three signals: what the system is doing, what the pools are doing, and what individual messages are doing. If you have only one of the three, debugging becomes hide-and-seek.

### System-level events (PSR-14)

Every Nexus subsystem emits structured events through the PSR-14 dispatcher you pass to `ActorSystem::create()`:

```php title="src/Bootstrap/ObservabilityBootstrap.php"
$dispatcher->listen(ConnectionTaken::class, function (ConnectionTaken $e): void {
    $this->metrics->histogram(
        'db.connection.wait_ms',
        $e->waitDuration->toMillis(),
        ['pool' => $e->poolName],
    );
});

$dispatcher->listen(PoolExhausted::class, function (PoolExhausted $e): void {
    $this->metrics->increment('db.pool.exhausted', ['pool' => $e->poolName]);
});
```

Subscribe with whatever dispatcher you already use. Aggregate into Prometheus counters, OpenTelemetry spans, or any other sink. The framework emits the events; the adapter is yours to write.

### Pool stats

Snapshot any pool on demand:

```php title="src/Health/PoolHealthCheck.php"
$stats = $connPool->stats();
// $stats->idle, ->inUse, ->total
// $stats->waitingCoroutines, ->totalBorrows, ->totalWaits, ->totalTimeouts
```

`waitingCoroutines`, `totalWaits`, and `totalTimeouts` are the fields to put on a dashboard. A non-zero `totalTimeouts` means load is being shed via `PoolExhaustedToServiceUnavailable`. A persistently non-zero `waitingCoroutines` means you're at saturation — raise `max` or shorten work.

### Per-message visibility

Use `$ctx->log()` inside actor handlers. It returns a PSR-3 logger pre-scoped to the actor's path:

```php title="src/Actor/LedgerActor.php"
$ctx->log()->info('deposit accepted', [
    'ownerId'     => $ownerId,
    'amountCents' => $amount,
]);
// Logs: [INFO] [/user/wallets/wallet-alice] deposit accepted
```

The logger is backed by `NexusLogger`, which enqueues into a `LogActor` mailbox and returns immediately. The hot path pays no I/O cost.

## What to log inside an actor

**Always log:**
- `PreStart` and `PostStop` — the lifecycle events make the actor's state machine visible in your log aggregator
- Any command that produces a state transition — this is your audit trail
- Supervisor-triggered restarts with the exception class and the actor path

**Log at DEBUG, not INFO:**
- Per-message type on receive
- Per-reply with timing
- Dead-letter receipts

**Never log:**
- The full message payload of high-volume actors — sample instead
- Anything containing PII or credentials — mask at the handler level before logging

## What to put on the dashboard

A first-pass production dashboard should include:

- **HTTP rate** by route, broken out by status (200 / 4xx / 5xx / 503)
- **Pool inUse / total** as a stacked area, one panel per pool
- **`totalTimeouts` rate** — any non-zero value means load is being shed
- **Active actor count** across workers if you expose it
- **Restart rate** — should be flat at zero in a healthy system; spikes correlate with bugs or upstream failures
- **p99 ask latency** per command type — tail growth signals contention or a blocking call inside a handler

If you have room for only two charts: pool-wait-time and restart-rate. They're the earliest leading indicators of trouble.

## Tracing across actors

Carry trace context in the message itself:

```php title="src/Tracing/TraceContext.php"
final readonly class TraceContext
{
    public function __construct(
        public string $traceId,
        public string $spanId,
    ) {}
}

final readonly class Deposit
{
    public function __construct(
        public TraceContext $tracing,
        public int $amountCents,
    ) {}
}
```

The HTTP layer stamps it from incoming W3C trace-context headers. Each actor propagates it forward when it sends new messages. This pattern requires no framework hooks and works with any tracing backend.

## Debugging tactics

**Look at dead letters first.** Anything sent to a stopped actor lands there. Subscribe a watcher actor to `$system->deadLetters()` and surface the count on your dashboard.

**Add a `Diagnose` command.** Include a debug command in your actor's protocol that replies with its current state (sanitised). Inspectable on demand without touching production state.

**Use `StepRuntime` in a reproducer.** When a bug is timing-sensitive, switch to `StepRuntime` with a `TestClock` and step through messages one at a time. Race conditions become deterministic.

**Watch the supervision tree.** If an actor restarts in a tight loop, the backoff strategy is too lenient or the decider is misclassifying the exception. Add explicit `ChildFailed` signal handling in the parent to log the exception and the restart count.

## Next steps

- [Testing actors](./testing.md) — `StepRuntime` and deterministic time for reproducing timing bugs
- [Scaling out](./scaling.md) — how worker count and pool size interact with the metrics described here
- [Actors](../core-concepts/actors.md) — how the actor model handles failures and isolation
