---
title: Snapshots
related:
  - persistence/event-sourcing
  - persistence/single-writer
  - persistence/stores
  - reference/classes/event-sourced-behavior
---

# Snapshots

Snapshots checkpoint the actor's projected state at a known sequence number so that recovery can skip replaying the full event history from the beginning.

## When to snapshot

Without snapshots, recovery replays every event the actor has ever written. For long-lived aggregates this grows unbounded. A snapshot sets a recovery baseline: on startup, the engine loads the latest snapshot and only replays events written after it.

The overhead of taking a snapshot is one store write per N events. The payoff is proportional to how many events you skip on recovery. For actors that write thousands of events before stopping, snapshots are essential.

## `SnapshotStrategy`

`SnapshotStrategy` controls when the engine takes a snapshot. Configure it on `EventSourcedBehavior`:

```php title="src/Aggregates/OrderActor.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;

$behavior = EventSourcedBehavior::create($persistenceId, $emptyState, $commandHandler, $eventHandler)
    ->withEventStore($eventStore)
    ->withSnapshotStore($snapshotStore)
    ->withSnapshotStrategy(SnapshotStrategy::everyN(50))
    ->toBehavior();
```

Three built-in strategies:

| Factory | Behaviour |
|---|---|
| `SnapshotStrategy::everyN(int $n)` | Snapshot after every N-th event (sequence number divisible by N) |
| `SnapshotStrategy::never()` | Never snapshot — all recovery replays from sequence 0 |
| `SnapshotStrategy::predicate(Closure $fn)` | Custom predicate: `fn(state, event, seqNr): bool` |

:::note
A snapshot store must be configured via `->withSnapshotStore($store)` for snapshots to be taken. Setting a strategy without a store is silently ignored.
:::

## What a snapshot stores

The engine wraps the projected state in a `SnapshotEnvelope`:

| Field | Type | Description |
|---|---|---|
| `persistenceId` | `PersistenceId` | Identity of the actor stream |
| `sequenceNr` | `int` | Sequence number at the time of the snapshot |
| `state` | `object` | The serialized projected state |
| `stateType` | `string` | FQCN of the state class |
| `timestamp` | `DateTimeImmutable` | Wall-clock time of the snapshot |
| `writerId` | `Ulid` | Identity of the `ActorSystem` that wrote it |

The state object is serialized by the store's configured `MessageSerializer` (required — no default; see [stores](./stores.md) for the trusted-data vs allow-list choice).

## `RetentionPolicy`

After a snapshot is saved, old snapshots and — optionally — the events they cover can be pruned. Configure this via `RetentionPolicy`:

```php title="src/Aggregates/OrderActor.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;

$behavior = EventSourcedBehavior::create($persistenceId, $emptyState, $commandHandler, $eventHandler)
    ->withEventStore($eventStore)
    ->withSnapshotStore($snapshotStore)
    ->withSnapshotStrategy(SnapshotStrategy::everyN(50))
    ->withRetention(RetentionPolicy::snapshotAndEvents(keepSnapshots: 3, deleteEventsTo: true))
    ->toBehavior();
```

| Factory | Behaviour |
|---|---|
| `RetentionPolicy::none()` | Keep all snapshots and all events forever |
| `RetentionPolicy::snapshotAndEvents(int $keep, bool $deleteEventsTo)` | Keep the latest `$keep` snapshots; when `$deleteEventsTo` is `true`, also delete events up to the oldest retained snapshot's sequence number |

:::caution Event deletion is permanent
Setting `deleteEventsTo: true` calls `EventStore::deleteUpTo()` after each snapshot. Events deleted this way cannot be recovered. Do not enable this if you need to project events into read models.
:::

## Recovery acceleration in practice

With `SnapshotStrategy::everyN(50)` and a `RetentionPolicy` that keeps 3 snapshots:

- An actor with 10,000 events and a snapshot at event 9,950 recovers by loading one snapshot and replaying 50 events.
- Without snapshots, the same actor replays all 10,000 events.

For actors that write many small events frequently (counters, metrics accumulators), use a smaller N (e.g. `everyN(10)`). For aggregates that process occasional domain commands, `everyN(100)` is a reasonable default.

## See also

- [Event sourcing](./event-sourcing.md) — how snapshots fit into the recovery sequence.
- [Stores](./stores.md) — `SnapshotStore` implementations.
- [Single-writer guarantee](./single-writer.md) — `writerId` stamped on `SnapshotEnvelope`.
