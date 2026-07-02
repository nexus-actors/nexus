---
title: Testing persistent actors
related:
  - persistence/event-sourcing
  - persistence/durable-state
  - persistence/stores
  - runtimes/step
---

# Testing persistent actors

Test event-sourced and durable-state actors using `StepRuntime` with in-memory stores. `StepRuntime` gives you message-by-message control over execution, and in-memory stores eliminate database dependencies.

## Testing event-sourced actors

The `StepRuntime` pattern: create the system, spawn the actor, call `$runtime->step()` once per message, then assert.

```php title="tests/Unit/OrderActorTest.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime  = new StepRuntime();
$system   = ActorSystem::create('test', $runtime);

$eventStore = new InMemoryEventStore();

$behavior = EventSourcedBehavior::create(
    PersistenceId::of('Order', 'test-1'),
    new OrderState(),
    $commandHandler,
    $eventHandler,
)->withEventStore($eventStore)->toBehavior();

$ref = $system->spawn(Props::fromBehavior($behavior), 'order');

// Step through startup (recovery) and the first command
$runtime->drain(); // runs until mailbox is empty
$ref->tell(new PlaceOrder(['item-a']));
$runtime->step(); // processes PlaceOrder

// Assert events written to the store
$events = iterator_to_array($eventStore->load(PersistenceId::of('Order', 'test-1')));
self::assertCount(1, $events);
self::assertInstanceOf(OrderPlaced::class, $events[0]->event);
```

## Testing recovery replay

Seed the `InMemoryEventStore` with pre-written events before spawning the actor. When the actor starts, the `PersistenceEngine` replays those events and reconstructs state.

```php title="tests/Unit/OrderActorRecoveryTest.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Symfony\Component\Uid\Ulid;

$pid   = PersistenceId::of('Order', 'test-2');
$store = new InMemoryEventStore();

// Seed prior events — actor will replay these on startup
$store->persist($pid,
    new EventEnvelope($pid, 1, new OrderPlaced(['item-a']), OrderPlaced::class, new \DateTimeImmutable(), new Ulid()),
    new EventEnvelope($pid, 2, new OrderPlaced(['item-b']), OrderPlaced::class, new \DateTimeImmutable(), new Ulid()),
);

$runtime = new StepRuntime();
$system  = ActorSystem::create('test', $runtime);
$ref     = $system->spawn(Props::fromBehavior(buildBehavior($pid, $store)), 'order');

$runtime->drain(); // recovery replay runs here

// Actor state is now rebuilt from the two seeded events
$captured = null;
$ref->tell(new GetOrder(replyTo: $system->spawnAnonymous(captureRef($captured))));
$runtime->step();

self::assertCount(2, $captured->items);
```

## Testing durable-state actors

Durable-state testing follows the same pattern, using `InMemoryDurableStateStore`:

```php title="tests/Unit/UserProfileActorTest.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateBehavior;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$store   = new InMemoryDurableStateStore();
$runtime = new StepRuntime();
$system  = ActorSystem::create('test', $runtime);

$behavior = DurableStateBehavior::create(
    PersistenceId::of('UserProfile', 'user-1'),
    new UserProfile(),
    $commandHandler,
)->withStateStore($store)->toBehavior();

$ref = $system->spawn(Props::fromBehavior($behavior), 'profile');
$runtime->drain();

$ref->tell(new UpdateEmail('alice@example.com'));
$runtime->step();

// Assert persisted state directly from the store
$envelope = $store->load(PersistenceId::of('UserProfile', 'user-1'));
self::assertNotNull($envelope);
self::assertSame('alice@example.com', $envelope->state->email);
```

## Snapshot testing

To test that snapshots are taken at the correct interval, drive the actor past the snapshot threshold and inspect the `InMemorySnapshotStore`:

```php title="tests/Unit/SnapshotTest.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;

$pid           = PersistenceId::of('Counter', 'c1');
$eventStore    = new InMemoryEventStore();
$snapshotStore = new InMemorySnapshotStore();

$behavior = EventSourcedBehavior::create($pid, new CounterState(0), $commandHandler, $eventHandler)
    ->withEventStore($eventStore)
    ->withSnapshotStore($snapshotStore)
    ->withSnapshotStrategy(SnapshotStrategy::everyN(5))
    ->toBehavior();

// Drive 5 increments
for ($i = 0; $i < 5; $i++) {
    $ref->tell(new Increment());
    $runtime->step();
}

$snapshot = $snapshotStore->load($pid);
self::assertNotNull($snapshot);
self::assertSame(5, $snapshot->sequenceNr);
```

## See also

- [Event sourcing](./event-sourcing.md) — full API reference.
- [Durable state](./durable-state.md) — simplified persistence model.
- [Step runtime](../runtimes/step.md) — deterministic message-by-message execution.
- [Stores](./stores.md) — in-memory store implementations.
