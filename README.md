# Nexus

An actor system for PHP 8.5+. Type-safe actors, supervision trees, event sourcing, and multi-worker
scaling — Akka and OTP patterns in the PHP you already know.

> **Maturity:** Nexus is a pre-1.0 actor-system toolkit under active development. APIs are subject
> to change, several subsystems are experimental, and the project is not yet production-hardened.
> Evaluate it accordingly before depending on it for critical workloads.

## Why Nexus?

PHP has mature tools for HTTP request handling, but nothing serious for building concurrent,
long-running systems. When you need actors, supervision, or message-driven architectures, the
answer has always been "use a different language." Nexus changes that.

**The problem.** Building concurrent PHP systems today means stitching together queue workers,
process managers, and ad-hoc error recovery. There's no structured way to manage state across
concurrent units, no supervision hierarchy to handle failures automatically, and no type safety
for the messages flowing through your system.

**The approach.** Nexus brings battle-tested patterns from Akka and OTP to PHP with
Psalm-assisted protocol checking. Every `ActorRef<T>` is generic — with the optional Psalm plugin
and concrete annotations enabled, message type mismatches are caught at analysis time. This is
static analysis, not a runtime guarantee: at runtime boundaries (deserialization, dynamic
dispatch) types are not enforced. Behaviors are immutable value objects. State is explicitly
managed, never shared. The entire API is `readonly` and `final` by default.

**What makes it different:**

- **Two runtimes, one core API.** Write core actor code once. Run it on PHP Fibers during
  development and Swoole coroutines in production. Some features are runtime-specific — the
  worker pool and HTTP server are Swoole-only, and mailbox, shutdown, and cancellation semantics
  can differ between runtimes.
- **Supervision hierarchy.** Actors form a hierarchy. Parents supervise children. When something
  fails, the supervision strategy decides what happens. One-for-one restart directives with retry
  windows are implemented; all-for-one, escalation, and exponential-backoff strategies are
  currently incomplete.
- **PHP 8.5+ native.** Nexus uses `readonly class`, typed class constants, and generic templates
  throughout. Built for modern PHP, not retrofitted onto it.
- **PSR everywhere.** PSR-11 containers, PSR-3 logging, PSR-14 event dispatching, PSR-20 clocks.
  Nexus plugs into your existing stack.
- **Multi-worker scaling.** Scale actors across all CPU cores on a single machine via Swoole's
  `Thread\Pool`. Each worker runs an independent `ActorSystem`. `WorkerActorRef` delivers messages
  cross-thread via `Thread\Queue` — an indicative local microbenchmark measured ~260K msgs/sec per
  worker pair, but no published methodology exists yet and the committed performance suite is
  partially broken, so treat that figure as unverified. No application-level serializer is
  required (Swoole serializes internally).

## Quick Example

Two actors communicating with immutable messages using the Fiber runtime:

```php
<?php
declare(strict_types=1);

use Monadial\Nexus\Core\Actor\{ActorContext, ActorRef, ActorSystem, Behavior, Props};
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

readonly class Ping { public function __construct(public ActorRef $replyTo) {} }
readonly class Pong {}

$ponger = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
    if ($msg instanceof Ping) {
        $ctx->log()->info('Ponger received Ping');
        $msg->replyTo->tell(new Pong());
    }
    return Behavior::same();
});

$pinger = Behavior::setup(static function (ActorContext $ctx) use ($ponger): Behavior {
    $pongerRef = $ctx->spawn(Props::fromBehavior($ponger), 'ponger');
    $pongerRef->tell(new Ping($ctx->self()));

    return Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
        if ($msg instanceof Pong) {
            $ctx->log()->info('Pinger received Pong');
        }
        return Behavior::same();
    });
});

$runtime = new FiberRuntime();
$system = ActorSystem::create('ping-pong', $runtime);
$system->spawn(Props::fromBehavior($pinger), 'pinger');

$runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
    $system->shutdown(Duration::seconds(1));
});
$system->run();
```

## Features

- **Type-safe actors** — Psalm Level 1 generics across behaviors, refs, and contexts
- **Multiple runtimes** — Fiber runtime for development; Swoole runtime for production
- **Supervision trees** — one-for-one restart strategies with configurable retry limits (all-for-one, escalation, and backoff are incomplete)
- **Immutable messages** — `readonly class` message protocol enforced by Psalm plugin
- **Stashing** — buffer messages during transitional states with `$ctx->stash()` / `$ctx->unstashAll()`
- **Scheduled messages** — one-shot and repeating timers via `$ctx->scheduleOnce()` / `$ctx->scheduleRepeatedly()`
- **Ask pattern** — request-response with timeout: `$ref->ask($factory, Duration::millis(200))`
- **Dead letters** — undeliverable messages routed to the dead-letter endpoint
- **Persistence and event sourcing** — event-sourced actors and durable state actors with pluggable storage (in-memory, DBAL, Doctrine ORM)
- **Multi-worker scaling** — cross-worker messaging via `Swoole\Thread\Queue` (~260K msgs/sec per worker pair in an indicative local microbenchmark; methodology not yet published)

## Installation

```bash
composer require nexus-actors/core nexus-actors/runtime-fiber
```

For the Swoole production runtime:

```bash
composer require nexus-actors/runtime-swoole
```

For multi-worker scaling (requires ZTS PHP 8.5+ and Swoole 6.2.1+ with `--enable-swoole-thread`):

```bash
composer require nexus-actors/worker-pool nexus-actors/worker-pool-swoole
```

For persistence and event sourcing:

```bash
composer require nexus-actors/persistence

# With Doctrine DBAL storage:
composer require nexus-actors/persistence-dbal

# With Doctrine ORM storage:
composer require nexus-actors/persistence-doctrine
```

## Packages

| Package | Description |
|---|---|
| [`nexus-actors/core`](packages/nexus-core) | Behaviors, actors, supervision, mailboxes, and the core API |
| [`nexus-actors/runtime`](packages/nexus-runtime) | Shared runtime abstractions and async primitives (`Future`, `FutureSlot`, `Runtime`) |
| [`nexus-actors/runtime-fiber`](packages/nexus-runtime-fiber) | Fiber runtime — actors as PHP fibers with cooperative scheduling |
| [`nexus-actors/runtime-swoole`](packages/nexus-runtime-swoole) | Swoole runtime — actors as Swoole coroutines with native channels |
| [`nexus-actors/runtime-step`](packages/nexus-runtime-step) | Deterministic step runtime for tests — `step()`, `drain()`, `advanceTime()` |
| [`nexus-actors/worker-pool`](packages/nexus-worker-pool) | Worker pool contracts — `WorkerNode`, `WorkerActorRef`, hash ring, transport interfaces |
| [`nexus-actors/worker-pool-swoole`](packages/nexus-worker-pool-swoole) | Swoole thread-based scaling — `Thread\Queue` transport, `Thread\Map` directory, `WorkerPoolApp` |
| [`nexus-actors/cluster`](packages/nexus-cluster) | Remote contracts for future TCP-based multi-machine clustering |
| [`nexus-actors/persistence`](packages/nexus-persistence) | Persistence core — event sourcing, durable state, snapshot strategies, in-memory stores |
| [`nexus-actors/persistence-dbal`](packages/nexus-persistence-dbal) | DBAL persistence adapter — Doctrine DBAL-backed event, snapshot, and state stores |
| [`nexus-actors/persistence-doctrine`](packages/nexus-persistence-doctrine) | Doctrine ORM persistence adapter — entity-based event, snapshot, and state stores |
| [`nexus-actors/serialization`](packages/nexus-serialization) | Valinor-based message serialization with type registry |
| [`nexus-actors/app`](packages/nexus-app) | Application layer — declarative actor registration for single-process deployments |
| [`nexus-actors/psalm`](packages/nexus-psalm) | Psalm plugin for generic type inference and actor-protocol static analysis |
| [`nexus-actors/nexus`](packages/nexus) | Meta-package: core + runtime-fiber + serialization |

## Documentation

Full documentation: [nexusactors.com](https://nexusactors.com)

Start with the Nexus thesis:
- [When to use Nexus, when not to, and why](https://nexusactors.com/docs/core-concepts/nexus-thesis)

## Requirements

- PHP 8.5+
- Swoole 6.2.1+ compiled with `--enable-swoole-thread` (for `nexus-worker-pool-swoole` only)
- ZTS PHP (for `nexus-worker-pool-swoole` only)

## License

MIT
