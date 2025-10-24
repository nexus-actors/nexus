# Nexus

A production-grade actor system for PHP 8.5+.

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

## Installation

```bash
composer require monadial/nexus-core monadial/nexus-runtime-fiber
```

For the Swoole production runtime:

```bash
composer require monadial/nexus-runtime-swoole
```

## Packages

| Package | Description |
|---|---|
| [`monadial/nexus-core`](packages/nexus-core) | Behaviors, actors, supervision, mailboxes, and the core API |
| [`monadial/nexus-runtime-fiber`](packages/nexus-runtime-fiber) | Fiber runtime -- actors as PHP fibers with cooperative scheduling |
| [`monadial/nexus-runtime-swoole`](packages/nexus-runtime-swoole) | Swoole runtime -- actors as Swoole coroutines with native channels |
| [`monadial/nexus-serialization`](packages/nexus-serialization) | Valinor-based message serialization with type registry |
| [`monadial/nexus-psalm`](packages/nexus-psalm) | Psalm plugin for generic type inference on actors and behaviors |
| [`monadial/nexus`](packages/nexus) | Meta-package: core + Fiber runtime + serialization |

## Documentation

Full documentation is available at [nexus.monadial.com](https://nexus.monadial.com).

## Requirements

- PHP 8.5+
- Swoole 5.0+ (for the production Swoole runtime only)

## License

MIT
