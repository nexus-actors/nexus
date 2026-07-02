---
sidebar_position: 3
title: "Quick Start: Persistent Actors"
related:
  - getting-started/quick-start
  - persistence/overview
  - core-concepts/supervision
---

# Quick Start: Persistent Actors

This tutorial builds on the [basic quick start](./quick-start.md) by making the counter actor persistent — it survives restarts by storing events in a database. Every increment is recorded as an event, and when the actor restarts it replays those events to recover its state automatically.

## Step 1: Define commands and events

Persistent actors distinguish between commands (what the outside world asks for) and events (what actually happened). Commands are input; events are facts:

```php title="src/Messages/Messages.php"
<?php
declare(strict_types=1);

namespace App\Messages;

// Commands — requests from the outside world
final readonly class Increment {}

final readonly class GetCount {}

// Events — immutable facts that are persisted
final readonly class Incremented {}

// Replies
final readonly class CountReply
{
    public function __construct(public int $count) {}
}
```

The `Increment` command causes an `Incremented` event. The event is what gets stored. The command is discarded after processing.

## Step 2: Define the state

State is a plain `readonly class`. It is rebuilt from events on every recovery — never stored directly:

```php title="src/CounterState.php"
<?php
declare(strict_types=1);

namespace App;

final readonly class CounterState
{
    public function __construct(public int $count = 0) {}
}
```

## Step 3: Create the event-sourced behavior

`EventSourcedBehavior` has two handlers:

- **Command handler** — receives the current state and a command, returns an `Effect` describing what events to persist.
- **Event handler** — receives the current state and an event, returns the new state. This runs both when persisting new events and when replaying on recovery.

```php title="src/CounterBehavior.php"
<?php
declare(strict_types=1);

namespace App;

use App\Messages\CountReply;
use App\Messages\GetCount;
use App\Messages\Increment;
use App\Messages\Incremented;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\PersistenceId;

$behavior = EventSourcedBehavior::create(
    persistenceId: PersistenceId::of('counter', 'counter-1'),
    emptyState: new CounterState(),
    commandHandler: static function (
        object $state,
        ActorContext $ctx,
        object $command,
    ): Effect {
        if ($command instanceof Increment) {
            return Effect::persist(new Incremented());
        }
        if ($command instanceof GetCount) {
            $ctx->reply(new CountReply($state->count));

            return Effect::none();
        }

        return Effect::none();
    },
    eventHandler: static function (object $state, object $event): object {
        if ($event instanceof Incremented) {
            return new CounterState($state->count + 1);
        }

        return $state;
    },
)
->withEventStore(new InMemoryEventStore())
->toBehavior();
```

Key differences from the basic counter:

- State changes go through events, not direct mutation.
- `Effect::persist()` saves the event; the event handler then updates state.
- The actor replies to `GetCount` via `$ctx->reply()`, so callers can use `ask()`.
- The event handler is pure — no side effects, no I/O.

## Step 4: Run it

```php title="src/main.php"
<?php
declare(strict_types=1);

namespace App;

use App\Messages\CountReply;
use App\Messages\GetCount;
use App\Messages\Increment;
use App\Messages\Incremented;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

require __DIR__ . '/../vendor/autoload.php';

$runtime = new FiberRuntime();
$system = ActorSystem::create('persistent-demo', $runtime);

$behavior = EventSourcedBehavior::create(
    persistenceId: PersistenceId::of('counter', 'counter-1'),
    emptyState: new CounterState(),
    commandHandler: static function (object $state, ActorContext $ctx, object $command): Effect {
        if ($command instanceof Increment) {
            return Effect::persist(new Incremented());
        }
        if ($command instanceof GetCount) {
            $ctx->reply(new CountReply($state->count));

            return Effect::none();
        }

        return Effect::none();
    },
    eventHandler: static function (object $state, object $event): object {
        if ($event instanceof Incremented) {
            return new CounterState($state->count + 1);
        }

        return $state;
    },
)
->withEventStore(new InMemoryEventStore())
->toBehavior();

$counterRef = $system->spawn(Props::fromBehavior($behavior), 'counter');

for ($i = 0; $i < 5; $i++) {
    $counterRef->tell(new Increment());
}

$runtime->spawn(static function () use ($counterRef, $system): void {
    /** @var CountReply $reply */
    $reply = $counterRef->ask(new GetCount(), Duration::seconds(5))->await();
    echo 'Count: ' . $reply->count . PHP_EOL; // Count: 5

    $system->shutdown(Duration::seconds(1));
});

$system->run();
```

The important difference is not visible in this output — it is what happens when the process restarts. With persistence, the counter recovers to `5` automatically without any external intervention.

## How recovery works

When a persistent actor starts, it replays its event log before accepting new commands:

1. **Load events** — the event store returns all events for `PersistenceId::of('counter', 'counter-1')`.
2. **Replay** — each event passes through the event handler, rebuilding state step by step: `0 → 1 → 2 → 3 → 4 → 5`.
3. **Ready** — the actor starts processing new commands with `count = 5`.

Commands that arrive during recovery are automatically stashed and processed once recovery completes. Callers do not need to know whether the actor has finished recovering.

## Use a real database

Replace `InMemoryEventStore` with a DBAL or Doctrine store for production:

```php title="src/bootstrap.php"
<?php
declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Persistence\Dbal\DbalEventStore;

$connection = DriverManager::getConnection($params);
$eventStore = new DbalEventStore($connection, $serializer);
```

The actor code stays exactly the same. Only the store changes.

## Add snapshots

For actors with long event histories, replay can be slow. Snapshots save the full state periodically so recovery only needs to replay events after the snapshot:

```php title="src/CounterBehavior.php"
<?php
declare(strict_types=1);

use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;

$behavior = EventSourcedBehavior::create(/* ... */)
    ->withEventStore($eventStore)
    ->withSnapshotStore($snapshotStore)
    ->withSnapshotStrategy(SnapshotStrategy::everyN(100))
    ->toBehavior();
```

Recovery then loads the latest snapshot and replays only the events that occurred after it.

## Durable State alternative

If you do not need an event history — only the latest state — use `DurableStateBehavior` instead:

```php title="src/DurableCounterBehavior.php"
<?php
declare(strict_types=1);

namespace App;

use App\Messages\CountReply;
use App\Messages\GetCount;
use App\Messages\Increment;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableEffect;
use Monadial\Nexus\Persistence\State\DurableStateBehavior;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;

$behavior = DurableStateBehavior::create(
    persistenceId: PersistenceId::of('counter', 'counter-1'),
    emptyState: new CounterState(),
    commandHandler: static function (
        object $state,
        ActorContext $ctx,
        object $command,
    ): DurableEffect {
        if ($command instanceof Increment) {
            return DurableEffect::persist(new CounterState($state->count + 1));
        }
        if ($command instanceof GetCount) {
            $ctx->reply(new CountReply($state->count));

            return DurableEffect::none();
        }

        return DurableEffect::none();
    },
)
->withStateStore(new InMemoryDurableStateStore())
->toBehavior();
```

No events, no event handler, no replay. The store saves the current state directly and loads it on recovery.

## Comparison

| | Basic actor | Event-sourced actor | Durable state actor |
|---|---|---|---|
| State survives restart | No | Yes | Yes |
| Audit trail | No | Yes (full event history) | No |
| Recovery method | None | Replay events (or snapshot + tail) | Load latest state |
| Handler count | 1 (message handler) | 2 (command + event) | 1 (command handler) |
| Storage growth | None | Grows with events | Fixed per actor |

## Next steps

- [Persistence in depth](../persistence/overview.md) — effects, snapshots, retention policies, and storage backends.
- [Supervision](../core-concepts/supervision.md) — automatic restart on failure (works with persistent actors too).
- [Key Concepts](./concepts.md) — the actor model fundamentals.
