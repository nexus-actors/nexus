---
title: "ADR 0006: Persistence via Event Sourcing and Durable State"
sidebar_position: 6
related:
  - architecture/adrs/0001-actor-model-architecture
  - architecture/adrs/0002-immutable-behavior-api
  - core-concepts/actors
---

# ADR 0006: Persistence via Event Sourcing and Durable State

## Status

Accepted

## Context

Actors lose their state when they stop or crash. For many use cases (shopping carts, accounts, workflows), state must survive restarts. Two persistence patterns are established:

1. **Event sourcing**: Persist a sequence of events. Rebuild state by replaying events. Full audit trail.
2. **Durable state**: Persist the current state directly. Simpler, no event history.

Both patterns are valuable for different use cases. The persistence layer must integrate with the existing behavior system without modifying `nexus-core`.

## Decision

Implement persistence as a behavior wrapper layer:

- **`PersistenceEngine`**: Wraps any `Behavior<T>` to add event sourcing. Handles recovery (load snapshot → replay events), event persistence, and snapshot management.
- **`Effect`** type: Command handlers return effects instead of behaviors. `Effect::persist(events)` to persist, `Effect::none()` to skip, with `thenReply()` and `thenRun()` chaining.
- **`DurableStateEngine`**: Simpler variant that persists current state. `DurableEffect::persist($state)`.
- **Two API styles**: Functional (`EventSourcedBehavior::create()`) and class-based (`AbstractEventSourcedActor`).
- **Snapshot strategies**: `SnapshotStrategy::everyN(100)` for periodic snapshots, reducing replay overhead.
- **Pluggable stores**: `EventStore`, `SnapshotStore`, `DurableStateStore` interfaces with in-memory, DBAL, and Doctrine ORM implementations.

Three packages:

- `nexus-persistence`: Core interfaces, in-memory stores, engines, behaviors.
- `nexus-persistence-dbal`: Doctrine DBAL adapter (raw SQL, lightweight).
- `nexus-persistence-doctrine`: Doctrine ORM adapter (entity-based, full ORM integration).

## Consequences

- Zero changes to `nexus-core` — persistence is purely additive.
- Recovery is automatic: actors rebuild state on startup.
- Event sourcing provides a complete audit trail of all state changes.
- Snapshot + event replay balances recovery speed with storage efficiency.
- The `Effect` type makes persistence explicit in the type system.
- The DBAL adapter is ~3x faster than the ORM adapter for high-throughput scenarios.
