---
title: PersistenceId
sidebar_position: 8
related:
  - persistence/overview
  - reference/classes/event-sourced-behavior
  - reference/classes/durable-state-behavior
---

# PersistenceId

Unique stable identity for a persistent actor — the primary key used by every event store and state store.

## What it does

`PersistenceId` combines an entity type (e.g. `"Order"`) and an entity ID (e.g. `"order-42"`) into a single canonical string `"Order|order-42"`. The pipe separator is forbidden inside the entity type to guarantee unambiguous round-trip parsing via `fromString()`. All event-store and state-store operations use the persistence ID as their primary key, so the value must be stable across restarts — use domain entity UUIDs or slugs, not database auto-increment integers that could be re-assigned. Two `PersistenceId` instances are equal when both parts match, checked via `equals()` or by comparing the `__toString()` output directly.

## Example

```php title="src/OrderActor.php"
use Monadial\Nexus\Persistence\PersistenceId;

// Construct from parts — the typical case
$id = PersistenceId::of('Order', $orderId);
echo $id;           // "Order|order-42"
echo $id->entityType; // "Order"
echo $id->entityId;   // "order-42"

// Round-trip from a stored string (e.g. read from a database column)
$restored = PersistenceId::fromString('Order|order-42');
$id->equals($restored); // true

// Pass to a behavior builder
$behavior = EventSourcedBehavior::create($id, new OrderState(), $cmdHandler, $evtHandler)
    ->withEventStore($eventStore)
    ->toBehavior();
```

## Key methods

- `PersistenceId::of(string $entityType, string $entityId): self` — construct from separate parts; throws `InvalidArgumentException` if either is empty or if `$entityType` contains `|`.
- `PersistenceId::fromString(string $value): self` — parse the canonical `"EntityType|entityId"` form; throws if no pipe is present.
- `toString(): string` — returns `"EntityType|entityId"`; also called implicitly by string casts via `__toString()`.
- `equals(PersistenceId $other): bool` — value equality check on both parts.
- `$id->entityType` / `$id->entityId` — readonly public properties for direct access without a method call.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Persistence-PersistenceId.html)

## See also

- [Persistence concept](../../persistence/overview) — event sourcing and durable state overview
- [EventSourcedBehavior](event-sourced-behavior) — uses `PersistenceId` to key the event stream
- [DurableStateBehavior](durable-state-behavior) — uses `PersistenceId` to key the state snapshot
