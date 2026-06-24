---
title: Single-writer guarantee
related:
  - persistence/event-sourcing
  - persistence/snapshots
  - persistence/stores
  - reference/classes/event-sourced-behavior
---

# Single-writer guarantee

Persistent actors enforce the single-writer principle: exactly one `ActorSystem` writes to a given event stream at any time. This prevents split-brain data corruption when multiple processes share the same event store.

## The design

Every `ActorSystem` generates a ULID as its `writerId` at boot time. The engine stamps this ULID on every `EventEnvelope` and `SnapshotEnvelope` it persists. When an actor recovers, the `ReplayFilter` checks whether the event stream was written by exactly one writer. If it detects events from more than one writer, it applies the configured conflict mode.

```php title="src/Bootstrap/ActorSystemSetup.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$system = ActorSystem::create('orders', new FiberRuntime());
// $system->writerId() returns a unique ULID, e.g. "01J4XGVT..."
```

Override the writer ID on `EventSourcedBehavior` when migrating data or writing deterministic tests:

```php title="src/Aggregates/OrderActor.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Symfony\Component\Uid\Ulid;

$behavior = EventSourcedBehavior::create($persistenceId, $emptyState, $commandHandler, $eventHandler)
    ->withEventStore($eventStore)
    ->withWriterId(Ulid::fromString('01J4XGVT00000000000000000A'))
    ->toBehavior();
```

## `ReplayFilter`

`ReplayFilter` applies conflict detection during event replay. Set it via `->withReplayFilter(...)`:

```php title="src/Aggregates/OrderActor.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\Recovery\ReplayFilter;

$behavior = EventSourcedBehavior::create($persistenceId, $emptyState, $commandHandler, $eventHandler)
    ->withEventStore($eventStore)
    ->withReplayFilter(ReplayFilter::fail())
    ->toBehavior();
```

The four modes:

| Mode | Factory | Behaviour on conflict |
|---|---|---|
| `Fail` | `ReplayFilter::fail()` | Throw `WriterConflictException` immediately — default; safest for production |
| `Warn` | `ReplayFilter::warn()` | Log a PSR-3 warning and continue replaying all events |
| `RepairByDiscardOld` | `ReplayFilter::repairByDiscardOld()` | Discard events from all writers except the latest; continue recovery with only the most recent writer's events |
| `Off` | `ReplayFilter::off()` | Skip conflict detection entirely — for testing or trusted single-node deploys |

:::caution RepairByDiscardOld discards data
`RepairByDiscardOld` permanently drops events from earlier writers. Use it only in migration scenarios where you have already confirmed the earlier writer is gone.
:::

## `WriterConflictException`

`WriterConflictException` is thrown during recovery when `ReplayFilter::fail()` detects interleaved writers. It carries:

- `persistenceId` — the stream where the conflict was found.
- `expectedWriter` — the ULID of the first writer seen in the stream.
- `actualWriter` — the ULID of the conflicting writer.
- `sequenceNr` — the sequence number at which the conflict was detected.

The actor fails to start. The parent's supervision strategy handles the failure — typically `Directive::Stop` to prevent inconsistent state from being served.

## Practical rules

Keep exactly one `ActorSystem` writing to each persistence ID at any time. In a worker pool (multiple threads), each actor name maps deterministically to a single worker via the consistent hash ring, so each persistent entity has one writer. In a multi-machine deployment, route entity commands to a single designated node by entity ID.

## See also

- [Event sourcing](./event-sourcing.md) — where `writerId` is applied.
- [Snapshots](./snapshots.md) — `writerId` is also stamped on `SnapshotEnvelope`.
- [Scaling overview](../scaling/overview.md) — worker pool routing that preserves single-writer semantics via consistent hash ring.
