---
sidebar_position: 9
title: Observability
---

# Observability

> *"What do I want to see when an actor system is running?"*

Three things, in order: **what the actor system is doing**, **what
the pools are doing**, and **what individual messages are doing**.
If you have only one of the three, debug logs become hide-and-seek.

## The three layers

### 1. System-level events (PSR-14)

Every Nexus subsystem emits structured events through the
PSR-14 dispatcher you pass to `ActorSystem::create()`. Sample:

```php
// nexus-doctrine-dbal
ConnectionCreated(poolName)
ConnectionTaken(poolName, waitDuration)
ConnectionReleased(poolName, heldDuration)
PoolExhausted(poolName, stats)

// nexus-doctrine-orm
EntityManagerCreated(poolName)
EntityManagerCleared(poolName)
EntityManagerEvicted(poolName, reason)
```

Subscribe with whatever dispatcher you already use. Aggregate into
Prometheus counters / OpenTelemetry spans / etc. The framework
doesn't ship a Prometheus exporter (yet); the contract is yours to
adapt.

### 2. Pool stats

Snapshot any pool at any time:

```php
$stats = $connPool->stats();
// $stats->idle, ->inUse, ->total, ->waitingCoroutines,
// $stats->totalBorrows, ->totalWaits, ->totalTimeouts
```

The `waitingCoroutines` / `totalWaits` / `totalTimeouts` fields are the
ones you want on a dashboard. If `totalTimeouts > 0` you're shedding
load (`PoolExhaustedToServiceUnavailable` is mapping to 503); if
`waitingCoroutines` is persistently > 0 you're at saturation — raise
`max` or shorten work.

### 3. Per-message visibility

The async `NexusLogger` (PSR-3 with a `LogActor` mailbox behind it)
keeps the hot path's logger out of the request path:

```php
$log = NexusLogger::create($system, 'worker-' . $workerId)
    ->minLevel(Level::Debug)
    ->handler(new ConsoleHandler($stderr, new LineFormatter()))
    ->build();
```

Then in your actors:

```php
$ctx->log()->info('deposit accepted', ['ownerId' => $ownerId, 'amountCents' => $amount]);
```

The log call enqueues into the LogActor's mailbox and returns
immediately. The LogActor formats and writes asynchronously. Your
hot path doesn't pay for I/O.

## What to log inside an actor

**Always:**
- `PreStart` and `PostStop` (the lifecycle events make a state
  machine visible)
- Any command that produces a state transition (the audit trail)
- Supervisor-triggered restarts with the exception class

**Sometimes:**
- Per-message DEBUG with the message type
- Per-reply DEBUG with timing
- Dead-letter receipts

**Never:**
- Inside the hot path of receive loops at INFO. Use DEBUG, gated by
  a config flag.
- The full message payload of high-volume actors. Sample.
- Anything containing PII or credentials. Mask.

## What `$ctx->log()` adds

`ActorContext::log()` returns a PSR-3 logger that's already scoped to
the actor's path and id. You don't need to thread context manually:

```php
$ctx->log()->info('balance updated');
// Logs: [INFO] [/user/wallets/wallet-alice] balance updated
```

If you nest actors and want the parent's path on the child's logs,
that's already what you get — `ActorPath` strings concatenate.

## Tracing across actors

The framework doesn't enforce a trace-context propagation
convention (yet). The pattern most people land on:

```php
final readonly class Tracing
{
    public function __construct(public string $traceId, public string $spanId) {}
}

readonly class MyCommand
{
    public function __construct(public Tracing $tracing, public int $amount) {}
}
```

Every command carries the trace context. The HTTP layer stamps it
from incoming headers (`TraceContextMiddleware` in `nexus-http-toolkit`
does this for you with W3C trace-context). Actors propagate it
forward when they send new messages.

For automatic propagation across `ask()` calls, wrap the message in
an envelope object that carries the context — the framework doesn't
do this for you because there's no single tracing standard worth
locking in.

## What to put on the dashboard

A first-pass production dashboard:

- **HTTP rate** by route, broken out by status (200 / 4xx / 5xx /
  503)
- **Pool inUse / total** as a stacked area, one panel per pool
- **`totalTimeouts` rate** — a non-zero value means you're shedding
  load
- **Active actor count** (sum of `ActorSystem::children()` across
  workers if you expose it)
- **PoisonPill rate** and **restart rate** — both should be flat at
  zero in a healthy system; spikes correlate with bugs or upstream
  failures
- **p99 ask latency** per command type — if a command's tail grows,
  the actor handling it is either contended or doing something it
  shouldn't

If you only have room for two charts: pool-wait-time and
restart-rate. They're the two earliest leading indicators of
trouble.

## Debugging tactics

When something looks wrong:

**1. Look at dead letters.** Anything sent to a stopped actor
appears there. `$system->deadLetters()` is a real `ActorRef`; you
can subscribe to it from a "dead-letter watcher" actor and surface
the count.

**2. Send the actor a debug message.** Add a `Diagnose` command
to your actor's protocol that replies with the actor's current
state (sanitised). Inspectable on demand.

**3. Use `StepRuntime` in a reproducer.** When the bug is timing-
sensitive, switch to `StepRuntime` + `VirtualClock` and step
through messages one at a time. Race conditions become
deterministic.

**4. Watch the supervision tree.** If an actor restarts in a tight
loop, the supervisor's backoff strategy is too lenient or the
exception class isn't being decided correctly. Add explicit
`ChildFailed` logging in the parent's signal handler.

## What we don't ship (yet)

- A Prometheus exporter. PRs welcome.
- An OpenTelemetry tracer integration. The events are there; the
  bridge is yours to write.
- A live actor inspector (planned — see [Roadmap](../contributing/roadmap.md)).

In the meantime, the PSR-14 + PSR-3 surface is what you build on. If
your shop already has a stack, plug in.
