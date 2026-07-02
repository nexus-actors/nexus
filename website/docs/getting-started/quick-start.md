---
sidebar_position: 2
title: Quick Start
related:
  - getting-started/concepts
  - getting-started/persistent-actors
  - core-concepts/actors
  - core-concepts/behaviors
---

# Quick Start

This tutorial walks through building a counter actor that handles `Increment` messages and replies with the current count on `GetCount`. By the end you will have a working Nexus program that creates an actor system, spawns an actor, sends messages, and reads back a reply using the ask pattern.

## Step 1: Define your messages

Messages in Nexus are plain PHP objects. Use `readonly class` to make them immutable:

```php title="src/Messages/Increment.php"
<?php
declare(strict_types=1);

namespace App\Messages;

final readonly class Increment {}
```

```php title="src/Messages/GetCount.php"
<?php
declare(strict_types=1);

namespace App\Messages;

final readonly class GetCount {}
```

```php title="src/Messages/CountReply.php"
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

`GetCount` carries no `replyTo` field — the ask pattern handles reply routing automatically.

## Step 2: Define the actor behavior

A behavior is a function that receives a message and returns the next behavior. Use `Behavior::withState()` to thread state through each invocation. When the caller uses `ask()`, reply with `$ctx->reply()`:

```php title="src/CounterBehavior.php"
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
            $ctx->reply(new CountReply($count));

            return BehaviorWithState::same();
        }

        return BehaviorWithState::same();
    },
);
```

- `Behavior::withState(0, ...)` sets the initial state to `0`.
- The handler receives three arguments: the actor context, the incoming message, and the current state.
- `BehaviorWithState::next($count + 1)` keeps the same behavior with updated state.
- `$ctx->reply()` sends the response back to whoever called `ask()`.

## Step 3: Create the actor system and send messages

`ActorSystem` is the entry point. Use `FiberRuntime` for development. Because `ask()->await()` suspends the calling fiber, call it inside a fiber spawned on the runtime:

```php title="src/main.php"
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
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

require __DIR__ . '/../vendor/autoload.php';

$runtime = new FiberRuntime();
$system = ActorSystem::create('counter-demo', $runtime);

/** @var Behavior<object> $counterBehavior */
$counterBehavior = Behavior::withState(
    0,
    static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
        if ($msg instanceof Increment) {
            return BehaviorWithState::next($count + 1);
        }
        if ($msg instanceof GetCount) {
            $ctx->reply(new CountReply($count));

            return BehaviorWithState::same();
        }

        return BehaviorWithState::same();
    },
);

$counterRef = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');

for ($i = 0; $i < 5; $i++) {
    $counterRef->tell(new Increment());
}

// ask() must be called from inside a fiber
$runtime->spawn(static function () use ($counterRef, $system): void {
    /** @var CountReply $reply */
    $reply = $counterRef->ask(new GetCount(), Duration::seconds(5))->await();
    echo 'Count: ' . $reply->count . PHP_EOL; // Count: 5

    $system->shutdown(Duration::seconds(1));
});

$system->run();
```

## What we built

- `FiberRuntime` provides a cooperative scheduler backed by PHP fibers — each actor runs in its own fiber.
- `ActorSystem::create()` sets up the actor hierarchy with a `/user` guardian that parents all top-level actors.
- `tell()` is fire-and-forget: it enqueues a message and returns immediately.
- `ask()->await()` suspends the current fiber until the actor calls `$ctx->reply()`, then returns the reply value typed as `CountReply`.
- `$system->run()` enters the event loop and blocks until `shutdown()` completes.

## Stateless behaviors

Not every actor needs state. For simple message handlers, use `Behavior::receive()`:

```php title="src/LoggerBehavior.php"
<?php
declare(strict_types=1);

namespace App;

use App\Messages\Increment;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;

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

`Behavior::same()` keeps the current behavior for the next message.

## Switching to Swoole

To run with the Swoole runtime in production, swap the runtime at the composition root:

```php title="src/main-swoole.php"
<?php
declare(strict_types=1);

use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

$runtime = new SwooleRuntime();
$system = ActorSystem::create('counter-demo', $runtime);
```

Everything else stays the same. The core APIs are runtime-agnostic.

## Next steps

- [Persistent Actors](./persistent-actors.md) — make actors survive restarts with event sourcing.
- [Key Concepts](./concepts.md) — understand the actor model in depth.
- [Supervision](../core-concepts/supervision.md) — learn how parent actors handle child failures.
