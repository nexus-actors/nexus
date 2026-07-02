---
sidebar_position: 9
title: nexus-persistence
related:
  - packages/persistence-dbal
  - packages/persistence-doctrine
  - persistence/overview
---

# nexus-persistence

Core persistence abstractions for Nexus actors: event sourcing, durable state, effects, recovery, and in-memory stores for testing.

## What's in this package

**Event-sourced actors** (`Monadial\Nexus\Persistence\EventSourced\`)

- `EventSourcedBehavior` — functional builder; `create` → `withEventStore` → `withSnapshotStore` → `withSnapshotStrategy` → `withRetention` → `withReplayFilter` → `toBehavior`
- `AbstractEventSourcedActor` — class-based alternative
- `Effect` — `persist`, `none`, `unhandled`, `stash`, `stop`, `reply`, `thenRun`, `thenReply`
- `SnapshotStrategy` — `everyN`, `never`, `predicate`
- `RetentionPolicy` — `none`, `snapshotAndEvents`

**Durable-state actors** (`Monadial\Nexus\Persistence\State\`)

- `DurableStateBehavior` — functional builder; `create` → `withStateStore` → `withReplayFilter` → `toBehavior`
- `AbstractDurableStateActor` — class-based alternative
- `DurableEffect` — `persist`, `none`, `unhandled`, `stash`, `stop`, `reply`, `thenRun`, `thenReply`

**Store interfaces**

- `EventStore`, `EventEnvelope`, `InMemoryEventStore`
- `SnapshotStore`, `SnapshotEnvelope`, `InMemorySnapshotStore`
- `DurableStateStore`, `DurableStateEnvelope`, `InMemoryDurableStateStore`

**Recovery** — `ReplayFilter`, `ReplayFilterMode` (`Fail`, `Warn`, `RepairByDiscardOld`, `Off`)

**Exceptions** — `RecoveryException`, `ConcurrentModificationException`, `WriterConflictException`

## Install

```bash
composer require nexus-actors/persistence
```

## Quick example

<!-- verify:skip: requires a running actor system -->
```php title="src/Actor/OrderActor.php" verify:skip
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\InMemoryEventStore;
use Monadial\Nexus\Persistence\PersistenceId;

$behavior = EventSourcedBehavior::create(
    PersistenceId::of('Order', $orderId),
    new OrderState(),
    commandHandler: fn($state, $cmd) => match(true) {
        $cmd instanceof PlaceOrder => Effect::persist(new OrderPlaced($cmd->id)),
        default => Effect::unhandled(),
    },
    eventHandler: fn($state, $event) => $state->apply($event),
)
    ->withEventStore(new InMemoryEventStore())
    ->withSnapshotStrategy(SnapshotStrategy::everyN(10))
    ->toBehavior();
```

## See also

- [Persistence / overview](../persistence/overview.md) — choosing between event sourcing and durable state; conceptual guide
- [nexus-persistence-dbal](./persistence-dbal.md) — SQL backend
- [nexus-persistence-doctrine](./persistence-doctrine.md) — ORM backend
