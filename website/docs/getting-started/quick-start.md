---
sidebar_position: 2
title: Quick Start
---

# Quick Start

This tutorial walks through building a counter actor that handles `Increment`
messages and replies with the current count on `GetCount`. By the end you will
have a working Nexus program that creates an actor system, spawns actors,
sends messages, and shuts down cleanly.

## Step 1: Define your messages

Messages in Nexus are plain PHP objects. Use `readonly class` to make them
immutable:

```php
<?php
declare(strict_types=1);

namespace App\Messages;

final readonly class Increment
{
}
```

```php
<?php
declare(strict_types=1);

namespace App\Messages;

use Monadial\Nexus\Core\Actor\ActorRef;

final readonly class GetCount
{
    /**
     * @param ActorRef<object> $replyTo
     */
    public function __construct(
        public ActorRef $replyTo,
    ) {}
}
```

```php
<?php
declare(strict_types=1);

namespace App\Messages;

final readonly class CountReply
{
    public function __construct(
        public int $count,
    ) {}
}
```

The `GetCount` message carries an `ActorRef` so the counter knows where to send
its reply. This is the standard request-reply pattern in actor systems.

## Step 2: Define the actor behavior

A behavior is a function that receives a message and returns the next behavior.
For stateful actors, use `Behavior::withState()` which threads state through
each invocation:

```php
<?php
declare(strict_types=1);

namespace App;

use App\Messages\CountReply;
use App\Messages\GetCount;
use App\Messages\Increment;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;

/** @var Behavior<object> $counterBehavior */
$counterBehavior = Behavior::withState(
    0,
    static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
        if ($msg instanceof Increment) {
            return BehaviorWithState::next($count + 1);
        }

        if ($msg instanceof GetCount) {
            $msg->replyTo->tell(new CountReply($count));

            return BehaviorWithState::same();
        }

        return BehaviorWithState::same();
    },
);
```

Key points:

- The first argument to `Behavior::withState()` is the initial state (`0`).
- The handler receives three arguments: the actor context, the incoming message,
  and the current state.
- `BehaviorWithState::next($count + 1)` returns the same behavior with updated
  state.
- `BehaviorWithState::same()` keeps both the behavior and the state unchanged.

## Step 3: Create the actor system and spawn actors

An `ActorSystem` is the entry point. It requires a `Runtime` implementation --
use `FiberRuntime` for development:

```php
<?php
declare(strict_types=1);

namespace App;

use App\Messages\CountReply;
use App\Messages\GetCount;
use App\Messages\Increment;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

require __DIR__ . '/vendor/autoload.php';

// 1. Create the runtime and actor system
$runtime = new FiberRuntime();
$system = ActorSystem::create('counter-demo', $runtime);

// 2. Define the counter behavior
/** @var Behavior<object> $counterBehavior */
$counterBehavior = Behavior::withState(
    0,
    static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
        if ($msg instanceof Increment) {
            return BehaviorWithState::next($count + 1);
        }

        if ($msg instanceof GetCount) {
            $msg->replyTo->tell(new CountReply($count));

            return BehaviorWithState::same();
        }

        return BehaviorWithState::same();
    },
);

// 3. Spawn the counter actor
$counterRef = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');

// 4. Send messages
for ($i = 0; $i < 5; $i++) {
    $counterRef->tell(new Increment());
}

// 5. Create a probe actor to receive the reply
/** @var list<object> $captured */
$captured = [];

/** @var Behavior<object> $probeBehavior */
$probeBehavior = Behavior::receive(
    static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
        $captured[] = $msg;

        return Behavior::same();
    },
);

$probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

// 6. Ask the counter for its count
$counterRef->tell(new GetCount($probeRef));

// 7. Schedule a shutdown so the system exits
$runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
    $system->shutdown(Duration::seconds(1));
});

// 8. Run the event loop (blocks until shutdown)
$system->run();

// 9. Inspect the result
assert($captured[0] instanceof CountReply);
echo 'Count: ' . $captured[0]->count . PHP_EOL; // Count: 5
```

## What just happened

1. `FiberRuntime` provides a cooperative scheduler backed by PHP fibers. Each
   actor runs in its own fiber.
2. `ActorSystem::create()` sets up the actor hierarchy with a `/user` guardian
   that parents all top-level actors.
3. `Props::fromBehavior()` wraps a `Behavior` into a spawnable configuration.
   The `spawn()` call creates the actor and starts its message loop.
4. `tell()` is fire-and-forget: it enqueues a message in the actor's mailbox
   and returns immediately.
5. The counter processes messages sequentially. After five `Increment` messages,
   its internal state is `5`. When `GetCount` arrives, it sends a `CountReply`
   back to the probe actor.
6. `scheduleOnce()` registers a one-shot timer. After 500 ms it calls
   `shutdown()`, which sends a `PoisonPill` to every top-level actor and signals
   the runtime to stop once all fibers complete.
7. `$system->run()` enters the event loop and blocks until shutdown finishes.

## Stateless behaviors

Not every actor needs state. For simple message handlers, use `Behavior::receive()`:

```php
/** @var Behavior<object> $loggerBehavior */
$loggerBehavior = Behavior::receive(
    static function (ActorContext $ctx, object $msg): Behavior {
        $ctx->log()->info('Received: ' . $msg::class);

        return Behavior::same();
    },
);

$loggerRef = $system->spawn(Props::fromBehavior($loggerBehavior), 'logger');
$loggerRef->tell(new Increment());
```

`Behavior::same()` tells the system to keep the current behavior for the next
message.

## Switching to Swoole

To run with the Swoole runtime in production, swap the runtime at the
composition root:

```php
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

$runtime = new SwooleRuntime();
$system = ActorSystem::create('counter-demo', $runtime);
```

Everything else stays the same. The core APIs are runtime-agnostic.

## Next steps

- [Key Concepts](./concepts.md) -- understand the actor model in depth.
- [Supervision](/docs/core-concepts/supervision) -- learn how parent actors handle child failures.
