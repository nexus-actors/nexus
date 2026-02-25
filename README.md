# Nexus

> **Work in Progress.** Nexus is under active development and not production-ready.
> APIs may change without notice.

An actor system for PHP 8.5+.

## Why Nexus?

PHP has mature tools for HTTP request handling, but nothing serious for building concurrent, long-running systems. When you need actors, supervision, or message-driven architectures, the answer has always been "use a different language." Nexus changes that.

**The problem.** Building concurrent PHP systems today means stitching together queue workers, process managers, and ad-hoc error recovery. There's no structured way to manage state across concurrent units, no supervision hierarchy to handle failures automatically, and no type safety for the messages flowing through your system. You end up reimplementing half of Erlang/OTP, poorly, every time.

**The approach.** Nexus brings battle-tested patterns from Akka and OTP to PHP with zero compromises on type safety. Every `ActorRef<T>` is generic -- Psalm catches message type mismatches at analysis time, not in production at 3 AM. Behaviors are immutable value objects. State is explicitly managed, never shared. The entire API is `readonly` and `final` by default.

**What makes it different:**

- **Two runtimes, one API.** Write actor code once. Run it on PHP Fibers during development and Swoole coroutines in production. Your actors don't know or care which runtime they're on.
- **Supervision that actually works.** Actors form a hierarchy. Parents supervise children. When something fails, the supervision strategy decides what happens -- restart, stop, escalate, or back off exponentially. No more try/catch pyramids.
- **PHP 8.5+ native.** Nexus uses `readonly class`, pipe operator support, `#[NoDiscard]`, and generic templates throughout. It's built for modern PHP, not retrofitted onto it.
- **PSR everywhere.** PSR-11 containers for dependency injection. PSR-3 logging. PSR-14 event dispatching. PSR-20 clocks. Nexus plugs into your existing stack.
- **Multi-process scaling.** Scale actors across all CPU cores on a single machine via Swoole's `Process\Pool`. The `ActorRef` interface is the same whether the actor is local or on another worker. Location transparency is baked into the architecture, not bolted on.

## Quick Example

Two actors communicating with immutable messages using the Fiber runtime:

```php
<?php
declare(strict_types=1);

use Monadial\Nexus\Core\Actor\{ActorContext, ActorRef, ActorSystem, Behavior, Props};
use Monadial\Nexus\Core\Duration;
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

- **Type-safe actors** -- Psalm Level 1 generics across behaviors, refs, and contexts
- **Multiple runtimes** -- Fiber runtime for development and testing; Swoole runtime for production
- **Supervision trees** -- one-for-one and all-for-one strategies with configurable retry limits
- **Immutable messages** -- `readonly class` message protocol enforced by convention
- **Stashing** -- buffer messages during transitional states with `$ctx->stash()` / `$ctx->unstashAll()`
- **Scheduled messages** -- one-shot and repeating timers via `$ctx->scheduleOnce()` / `$ctx->scheduleRepeatedly()`
- **Ask pattern** -- request-response with timeout: `$ref->ask($factory, Duration::millis(200))`
- **Dead letters** -- undeliverable messages routed to the dead-letter endpoint
- **Persistence & event sourcing** -- event-sourced actors and durable state actors with pluggable storage backends (in-memory, DBAL, Doctrine ORM)
- **Multi-process scaling** -- transparent cross-worker messaging via Unix sockets and shared-memory directory

## Installation

```bash
composer require nexus-actors/core nexus-actors/runtime-fiber
```

For the Swoole production runtime:

```bash
composer require nexus-actors/runtime-swoole
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
| [`nexus-actors/runtime-fiber`](packages/nexus-runtime-fiber) | Fiber runtime -- actors as PHP fibers with cooperative scheduling |
| [`nexus-actors/runtime-swoole`](packages/nexus-runtime-swoole) | Swoole runtime -- actors as Swoole coroutines with native channels |
| [`nexus-actors/cluster`](packages/nexus-cluster) | Pure PHP scaling abstractions -- transport, directory, serializer interfaces |
| [`nexus-actors/cluster-swoole`](packages/nexus-cluster-swoole) | Swoole scaling -- Unix socket transport, shared-memory directory, cluster bootstrap |
| [`nexus-actors/persistence`](packages/nexus-persistence) | Persistence core -- event sourcing, durable state, snapshot strategies, in-memory stores |
| [`nexus-actors/persistence-dbal`](packages/nexus-persistence-dbal) | DBAL persistence adapter -- Doctrine DBAL-backed event, snapshot, and state stores |
| [`nexus-actors/persistence-doctrine`](packages/nexus-persistence-doctrine) | Doctrine ORM persistence adapter -- entity-based event, snapshot, and state stores |
| [`nexus-actors/serialization`](packages/nexus-serialization) | Valinor-based message serialization with type registry |
| [`nexus-actors/app`](packages/nexus-app) | Application layer -- PSR-11 container integration for actor systems |
| [`nexus-actors/psalm`](packages/nexus-psalm) | Psalm plugin for generic type inference on actors and behaviors |
| [`nexus-actors/nexus`](packages/nexus) | Meta-package: core + runtime + Fiber runtime + serialization |

## Documentation

Full documentation is available at [nexus-actors.github.io/nexus](https://nexus-actors.github.io/nexus/).

Start with the Nexus thesis for scope and tradeoffs:
- [When to use Nexus, when not to, and why](https://nexus-actors.github.io/nexus/docs/core-concepts/nexus-thesis)

## Requirements

- PHP 8.5+
- Swoole 5.0+ (for the production Swoole runtime only)

## License

MIT
