---
sidebar_position: 3
title: "Quick start: persistent actors"
---

# Quick start: persistent actors

The [basic quick start](./quick-start.md) showed a counter actor that loses its
state when the process stops. This tutorial makes the counter **persistent** --
it survives restarts by storing events in a database.

The example builds the same counter, but records every increment as a
persistent event. When the actor restarts, it replays those events to recover
its state automatically.

## Step 1: Define commands and events

Persistent actors distinguish between **commands** (what the outside world asks
for) and **events** (what actually happened). Commands are input; events are
facts.

```php
<?php
declare(strict_types=1);

namespace App\Messages;

use Monadial\Nexus\Core\Actor\ActorRef;

// Commands — requests from the outside world
final readonly class Increment {}

final readonly class GetCount
{
    /** @param ActorRef<object> $replyTo */
    public function __construct(public ActorRef $replyTo) {}
}

// Events — immutable facts that are persisted
final readonly class Incremented {}

// Replies
final readonly class CountReply
{
    public function __construct(public int $count) {}
}
```

The `Increment` command causes an `Incremented` event. The event is what gets
stored. The command is discarded after processing.

## Step 2: Define the state

State is a plain readonly class. It is rebuilt from events on every recovery --
never stored directly (that's the event sourcing model).

```php
<?php
declare(strict_types=1);

namespace App;

final readonly class CounterState
{
    public function __construct(public int $count = 0) {}
}
```

## Step 3: Create the event-sourced behavior

An `EventSourcedBehavior` has two handlers:

- **Command handler** -- receives the current state and a command, returns an
  `Effect` describing what events to persist.
- **Event handler** -- receives the current state and an event, returns the new
  state. This runs both when persisting new events and when replaying on
  recovery.

```php
<?php
declare(strict_types=1);

namespace App;

use App\Messages\CountReply;
use App\Messages\GetCount;
use App\Messages\Increment;
use App\Messages\Incremented;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\PersistenceId;

$behavior = EventSourcedBehavior::create(
    persistenceId: PersistenceId::of('counter', 'counter-1'),
    emptyState: new CounterState(),

    // Command handler: what should happen?
    commandHandler: static function (
        object $state,
        ActorContext $ctx,
        object $command,
    ): Effect {
        if ($command instanceof Increment) {
            // Persist an event — state will update via the event handler
            return Effect::persist(new Incremented());
        }

        if ($command instanceof GetCount) {
            // Read-only query — reply without persisting anything
            return Effect::reply($command->replyTo, new CountReply($state->count));
        }

        return Effect::none();
    },

    // Event handler: apply the fact to state
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
- `Effect::persist()` saves the event, then the event handler updates state.
- `Effect::reply()` responds to queries without touching the event log.
- The event handler is pure -- no side effects, no I/O.

## Step 4: Run it

```php
<?php
declare(strict_types=1);

namespace App;

use App\Messages\CountReply;
use App\Messages\GetCount;
use App\Messages\Increment;
use App\Messages\Incremented;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

require __DIR__ . '/vendor/autoload.php';

$eventStore = new InMemoryEventStore();

// 1. Create actor system
$runtime = new FiberRuntime();
$system = ActorSystem::create('persistent-demo', $runtime);

// 2. Define the persistent counter behavior
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
            return Effect::reply($command->replyTo, new CountReply($state->count));
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
->withEventStore($eventStore)
->toBehavior();

// 3. Spawn and send messages
$counterRef = $system->spawn(Props::fromBehavior($behavior), 'counter');

for ($i = 0; $i < 5; $i++) {
    $counterRef->tell(new Increment());
}

// 4. Probe actor to capture the reply
/** @var list<object> $captured */
$captured = [];
$probeRef = $system->spawn(Props::fromBehavior(
    Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
        $captured[] = $msg;
        return Behavior::same();
    }),
), 'probe');

$counterRef->tell(new GetCount($probeRef));

// 5. Shut down and check
$runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
    $system->shutdown(Duration::seconds(1));
});

$system->run();

assert($captured[0] instanceof CountReply);
echo 'Count: ' . $captured[0]->count . PHP_EOL; // Count: 5
```

This looks similar to the basic counter -- the difference is what happens when
the actor crashes or restarts. With persistence, the counter recovers its state
automatically.

## What recovery looks like

When a persistent actor starts, it replays its event log before accepting new
commands:

1. **Load events** -- the event store returns all events for
   `PersistenceId::of('counter', 'counter-1')`.
2. **Replay** -- each event passes through the event handler, rebuilding the
   state step by step: `0 → 1 → 2 → 3 → 4 → 5`.
3. **Ready** -- the actor starts processing new commands with `count = 5`.

Commands that arrive during recovery are automatically stashed and replayed
once recovery completes. Senders do not need to know whether the actor has
finished recovering.

## Using a real database

Replace `InMemoryEventStore` with a DBAL or Doctrine store for production:

```php
use Monadial\Nexus\Persistence\Dbal\DbalEventStore;

// Doctrine DBAL connection
$connection = DriverManager::getConnection($params);

$eventStore = new DbalEventStore($connection, $serializer);
```

The actor code stays exactly the same. Only the store changes.

## Adding snapshots

For actors with long event histories, replay can be slow. Snapshots save the
full state periodically so recovery only needs to replay events after the
snapshot:

```php
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;

$behavior = EventSourcedBehavior::create(/* ... */)
    ->withEventStore($eventStore)
    ->withSnapshotStore($snapshotStore)
    ->withSnapshotStrategy(SnapshotStrategy::everyN(100))
    ->toBehavior();
```

Now recovery loads the latest snapshot and replays only the events that
occurred after it -- instead of replaying the entire history.

## Durable state alternative

For actors that do not need an event history — just the latest state — use
`DurableStateBehavior` instead:

```php
use Monadial\Nexus\Persistence\State\DurableStateBehavior;
use Monadial\Nexus\Persistence\State\DurableEffect;
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
            return DurableEffect::persist(
                new CounterState($state->count + 1),
            );
        }
        if ($command instanceof GetCount) {
            return DurableEffect::reply(
                $command->replyTo,
                new CountReply($state->count),
            );
        }
        return DurableEffect::none();
    },
)
->withStateStore(new InMemoryDurableStateStore())
->toBehavior();
```

Simpler: no events, no event handler, no replay. The store saves the current
state directly and loads it on recovery.

## Comparison

| | Basic actor | Event-sourced actor | Durable state actor |
|---|---|---|---|
| State survives restart | No | Yes | Yes |
| Audit trail | No | Yes (full event history) | No |
| Recovery method | None | Replay events (or snapshot + tail) | Load latest state |
| Handler count | 1 (message handler) | 2 (command + event) | 1 (command handler) |
| Storage growth | None | Grows with events | Fixed per actor |

## Next steps

- [Persistence in depth](/docs/core-concepts/persistence) -- effects, snapshots, retention policies, storage backends.
- [Supervision](/docs/core-concepts/supervision) -- automatic restart on failure (works with persistent actors too).
- [Key Concepts](./concepts.md) -- the actor model fundamentals.
