---
title: EventStore
sidebar_position: 12
related:
  - persistence/overview
  - reference/classes/event-sourced-behavior
  - reference/classes/persistence-id
---

# EventStore

The interface for reading and writing event streams in an event-sourced actor system.

## What it does

`EventStore` is the persistence contract that `EventSourcedBehavior` (via the `PersistenceEngine`) uses to durably record domain events and replay them during actor recovery. It is intentionally minimal: four methods covering the full lifecycle of an event stream — append, load, delete, and sequence-number query.

Each event is wrapped in an `EventEnvelope` that carries the sequence number, writer ULID, and metadata alongside the raw event payload. The store guarantees append-only ordering: events are loaded in sequence-number order and the highest sequence number is used to resume replay without redundant scanning.

Nexus ships three implementations out of the box: `InMemoryEventStore` for unit tests, `DbalEventStore` for raw DBAL connections, and `DoctrineEventStore` for projects already using Doctrine ORM. All three honour the `deleteUpTo()` contract required by `RetentionPolicy` — making it safe to prune old events after a snapshot has been confirmed.

You only interact with `EventStore` directly when wiring a custom adapter or writing tests that inspect persisted events. In production actor code, the store is supplied once via `EventSourcedBehavior::withEventStore()` and never called directly.

## Example

```php title="src/Persistence/InMemoryEventStore.php"
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\PersistenceId;

// Using the built-in in-memory store for tests:
$store = new InMemoryEventStore();

$behavior = EventSourcedBehavior::create(
    PersistenceId::of('Order', $orderId),
    new OrderState(),
    $commandHandler,
    $eventHandler,
)
    ->withEventStore($store)
    ->withSnapshotStore($snapshotStore)
    ->toBehavior();

// Or implement your own adapter:
final class RedisEventStore implements EventStore
{
    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        foreach ($events as $envelope) {
            // append to Redis stream keyed by $id
        }
    }

    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        // yield EventEnvelope objects in ascending sequence order
    }

    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        // remove events with sequenceNr <= $toSequenceNr
    }

    public function highestSequenceNr(PersistenceId $id): int
    {
        // return the highest known sequence number, or 0 if no events exist
        return 0;
    }
}
```

## Key methods

- `persist(PersistenceId $id, EventEnvelope ...$events): void` — append one or more event envelopes atomically; must preserve insertion order within the call.
- `load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable<EventEnvelope>` — stream events in ascending sequence-number order; `$fromSequenceNr` is used to skip already-snapshotted history.
- `deleteUpTo(PersistenceId $id, int $toSequenceNr): void` — permanently remove all events with `sequenceNr <= $toSequenceNr`; called by `RetentionPolicy` after a snapshot is confirmed.
- `highestSequenceNr(PersistenceId $id): int` — return the current high-water mark; `0` means no events have been persisted yet.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Persistence-Event-EventStore.html)

## See also

- [Persistence concept](../../persistence/overview) — event sourcing model, snapshot strategies, and retention policies
- [EventSourcedBehavior](event-sourced-behavior) — the builder that accepts an `EventStore` instance via `withEventStore()`
- [PersistenceId](persistence-id) — the stable identity used as the primary key in the store
