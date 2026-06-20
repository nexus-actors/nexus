---
title: EventSourcedBehavior
sidebar_position: 9
related:
  - core-concepts/persistence
  - reference/classes/persistence-id
  - reference/classes/durable-state-behavior
  - reference/classes/behavior
---

# EventSourcedBehavior

Fluent builder for event-sourced actor behaviors — stores domain events and replays them to rebuild state.

## What it does

`EventSourcedBehavior` wires together four concerns — a `PersistenceId`, an initial empty state, a command handler that produces `Effect`s, and an event handler that folds events onto state — then compiles them into a `Behavior` via `toBehavior()`. On actor startup, the `PersistenceEngine` loads the latest snapshot (if any), replays all subsequent events through the event handler, and only then delivers the first user command. This guarantees that a restarted actor sees exactly the same state it had before the crash. Optional builder methods add snapshot frequency control (`withSnapshotStrategy()`), event pruning (`withRetention()`), and writer-conflict detection (`withReplayFilter()`).

## Example

```php title="src/OrderActor.php"
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;

$behavior = EventSourcedBehavior::create(
    PersistenceId::of('Order', $orderId),
    new OrderState(),
    // Command handler — must be a pure function; all side effects go in thenRun()
    static fn (ActorContext $ctx, object $cmd, OrderState $state): Effect => match (true) {
        $cmd instanceof PlaceOrder   => Effect::persist(new OrderPlaced($cmd->items))
            ->thenRun(static fn (OrderState $s) => $ctx->log()->info('Order placed')),
        $cmd instanceof CancelOrder  => Effect::persist(new OrderCancelled()),
        $cmd instanceof GetOrder     => Effect::none()->thenReply($ctx->sender(), fn ($s) => $s),
        default                      => Effect::none(),
    },
    // Event handler — pure fold; never produces side effects
    static fn (OrderState $state, object $event): OrderState => match (true) {
        $event instanceof OrderPlaced    => $state->withItems($event->items),
        $event instanceof OrderCancelled => $state->cancel(),
        default                          => $state,
    },
)
    ->withEventStore($eventStore)
    ->withSnapshotStore($snapshotStore)
    ->withSnapshotStrategy(SnapshotStrategy::everyN(10))
    ->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: true))
    ->toBehavior();
```

## Key methods

- `EventSourcedBehavior::create(PersistenceId, object $emptyState, Closure $commandHandler, Closure $eventHandler): self` — entry point; the four required arguments define the full event-sourcing contract.
- `->withEventStore(EventStore $store): self` — required; the backing store for persisting and replaying domain events.
- `->withSnapshotStore(SnapshotStore $store): self` — optional; enables periodic state snapshots for faster recovery.
- `->withSnapshotStrategy(SnapshotStrategy $strategy): self` — controls when snapshots are taken (e.g. `SnapshotStrategy::everyN(10)`).
- `->withRetention(RetentionPolicy $policy): self` — controls how many snapshots and events are kept after a snapshot is written.
- `->withReplayFilter(ReplayFilter $filter): self` — configures writer-conflict detection during event replay (`Fail`, `Warn`, `RepairByDiscardOld`, `Off`).
- `->toBehavior(): Behavior` — compile everything into a `Behavior` ready to pass to `Props::fromBehavior()`; throws `LogicException` if `withEventStore()` was not called.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Persistence-EventSourced-EventSourcedBehavior.html)

## See also

- [Persistence concept](../../core-concepts/persistence) — event sourcing model, recovery pipeline, and snapshot strategies
- [PersistenceId](persistence-id) — how to construct the stable identity used as the primary key
- [DurableStateBehavior](durable-state-behavior) — simpler alternative with no event history
- [Behavior](behavior) — the result type returned by `toBehavior()`
