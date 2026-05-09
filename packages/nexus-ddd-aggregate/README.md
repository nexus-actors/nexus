# nexus-ddd-aggregate

Aggregate persistence for the Nexus DDD framework. In-memory-first; concrete DBAL/Doctrine adapters live in follow-up packages.

The package gives a domain handler exactly two methods to depend on — `find()` and `save()` — and routes each call through a layered API (Repository to Strategy to Persister to Store) so the same handler code persists event-sourced aggregates and stateful aggregates without branching on kind.

## Persistence kinds

- **Event-sourced** (default) — events are the source of truth; aggregate state is replayed from the stream. Backed by `EventSourcedPersister` over `VersionedEventStore` + `SnapshotStore`.
- **Stateful** — the latest state IS the aggregate; no event history is kept. Backed by `StatefulPersister` over a row-level UPDATE-WHERE-version store.

`CompositePersistenceStrategy` dispatches by `instanceof` once at the public seam — handler code never sees the branch.

## Architecture

```
AggregateRepository<T>                  # public API: find(), save()
        |
        v
PersistenceStrategy                     # composite seam — routes by kind
        |
        +--> EventSourcedPersister      # for EventSourcedAggregateRoot subclasses
        |       |
        |       v
        |    VersionedEventStore        # OCC-protected appendIfVersion
        |    SnapshotStore              # baseline-and-replay accelerator
        |    UpcasterPipeline           # in-flight event migration
        |    StreamStrategy             # logical-stream resolution
        |
        +--> StatefulPersister          # for StatefulAggregateRoot subclasses
                |
                v
             (state row + version slot, UPDATE-WHERE-version)
```

## Quick start

```php
<?php
declare(strict_types=1);

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Repository\GenericAggregateRepository;
use Monadial\Nexus\Ddd\Aggregate\Strategy\CompositePersistenceStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\InMemoryEventSourcingStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\Stateful\InMemoryStatefulStrategy;
use Monadial\Nexus\Ddd\Aggregate\Versioning\DefaultUpcasterPipeline;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

final readonly class OrderPlaced implements DomainEvent
{
    public function __construct(public OrderId $orderId, public CustomerId $customer) {}
}

/** @extends EventSourcedAggregateRoot<OrderId, OrderPlaced> */
final class Order extends EventSourcedAggregateRoot
{
    private CustomerId $customer;

    public static function placeNew(OrderId $id, CustomerId $customer): self
    {
        $order = new self($id);
        $order->recordThat(new OrderPlaced($id, $customer));

        return $order;
    }

    public function id(): OrderId { return $this->id; }

    protected function apply(DomainEvent $event): void
    {
        if ($event instanceof OrderPlaced) {
            $this->customer = $event->customer;
        }
    }
}

$clock = new SystemClock(); // PSR-20 ClockInterface
$repository = new GenericAggregateRepository(
    Order::class,
    new CompositePersistenceStrategy(
        new InMemoryEventSourcingStrategy(new DefaultUpcasterPipeline([]), $clock),
        new InMemoryStatefulStrategy(),
    ),
);

$order = Order::placeNew($orderId, $customerId);
$repository->save($order);

$reloaded = $repository->find($orderId)->getOrCall(static fn() => throw new RuntimeException('not found'));
```

## PSR-14 lifecycle hooks

Wire any `Psr\EventDispatcher\EventDispatcherInterface` into `InMemoryVersionedEventStore` / `InMemorySnapshotStore` (both default to `NullEventDispatcher` when omitted). Listeners can subscribe at any level of the hierarchy:

```
HookEvent (abstract; carries AggregateStreamId)
    |
    +-- EventStoreHookEvent                # subscribe here for all event-store hooks
    |       BeforeEventStoreLoad / AfterEventStoreLoad
    |       BeforeEventStoreAppend / AfterEventStoreAppend / EventStoreAppendFailed
    |       BeforeEventStoreDelete / AfterEventStoreDelete
    |
    +-- SnapshotHookEvent                  # subscribe here for all snapshot hooks
            BeforeSnapshotLoad / AfterSnapshotLoad
            BeforeSnapshotSave / AfterSnapshotSave / SnapshotSaveFailed
            BeforeSnapshotDelete / AfterSnapshotDelete
```

Total: 14 concrete events. Subscribe broadly via `function(HookEvent $e)`, or narrowly via `function(AfterEventStoreAppend $e)`.

## Schema evolution

- `#[Event(name, version)]` — decorates `DomainEvent` classes with stable wire-format identity. `EventNameRegistry::scan()` enforces `(name, version)` uniqueness at boot.
- `#[TombstoneEvent(name, removedAt)]` — marks an event as silently skipped during replay (logging-only events that no longer affect state).
- `Upcaster<TIn, TOut of DomainEvent>` — pure transformation `(eventV_n, context) -> eventV_n+1`. Each historical version is its own typed class (`OrderPlacedV1`, `OrderPlacedV2`); the upcaster is the class-to-class bridge. `UpcasterRegistry::scan()` validates the chain has no gaps at boot.
- `SnapshotUpcaster<TIn, TOut of object>` — same pattern for snapshot state-version transitions.
- `#[BulkCommand($justification)]` — required on `iterable<T>`-returning methods on `AggregateRepository` subclasses. Documents *why* the bulk read exists and is enforced by the `AggregateRepositoryReadOnlyBulk` Psalm rule. Bulk WRITE methods are forbidden.

`#[FrameworkAccessor]` (a separate v6 §6.4 concept) is the escape hatch for narrow framework integration on aggregate types themselves; it lives outside this package.

## Known limitations

- **Snapshot WRITE path is deferred.** `EventSourcedAggregateRoot` has no public `state()` accessor by design (would break `NoGettersSettersOnAggregateRule`). The follow-up adds a state-extraction hook to `EventSourcedAggregateRootAccessor` and reinstates the snapshot save.
- **`ReplaySafeApplyRule` strengthening (clock/RNG/logger forbid) deferred.** The Psalm rule today restricts `recordThat()` from inside `apply()`; the strengthened version forbidding side-effecting reads ships in a follow-up.
- **`EventHandlerInboxTransactionRule` deferred.** The inbox-tx invariant for cross-aggregate coordination ships in a follow-up.
- **Causation-chain integrity across `ActorSystem` writer-id changes** — a cross-cutting concern outside this package's scope. See umbrella spec v6 §25.6.1.

## Spec references

- v6 §9 — Persistence Layer (Repository, PersistenceStrategy, event store, OCC, snapshotting)
- v6 §10 — Event Versioning and Schema Evolution (stable identity, upcasters, tombstones)
- v6 §25.6 — Known Limitations
