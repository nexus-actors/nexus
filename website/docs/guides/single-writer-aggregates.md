---
sidebar_position: 4
title: How to guarantee single-writer aggregates
related:
  - core-concepts/actors
  - persistence/overview
  - guides/message-design
  - scaling/overview
---

# How to guarantee single-writer aggregates

When multiple concurrent requests target the same aggregate — a wallet, an order, a user account — you need to guarantee that only one write happens at a time.

## Solution

Give each aggregate a single actor. The mailbox serialises every write for that identity. No row locks, no version columns to retry, no compensation logic for lost updates.

```php title="src/Http/Handler/LedgerRecordHandler.php"
$ledgers = LedgerActor::factory($system, $ormConfig, $connParams);

// First call for 'alice': spawns a LedgerActor.
// Subsequent calls: return the cached ref.
// Both tell()s land in the same mailbox in arrival order.
$ledgers->of('alice')->tell(new RecordLedger(LedgerKind::Deposit, 12345));
$ledgers->of('alice')->tell(new RecordLedger(LedgerKind::Withdraw,  500));
```

`EntityRefFactory::of($id)` enforces single-writer: the same identity always resolves to the same actor ref, regardless of which HTTP handler calls it. The actor processes messages one at a time. Running-total invariants hold without a `SELECT … FOR UPDATE`.

## How it works

The actor's mailbox is a bounded FIFO queue. Every `tell()` enqueues an `Envelope`; the actor dequeues and processes one message at a time. Concurrent calls from different HTTP handlers land in the mailbox in the order the worker thread saw them. The second caller's write sees the state left by the first.

## Variations

### Multi-thread deployments: route by identity

Single-writer within one `ActorSystem` is automatic. Across multiple worker threads, each with its own `ActorSystem`, the same aggregate has a potential writer per thread.

The [worker pool](../scaling/overview.md) solves this with a consistent hash ring: owner id `alice` hashes to thread 2, owner id `bob` hashes to thread 0, and every request for `alice` lands on thread 2 because both the HTTP router and the worker-to-worker transport use the same ring. Only one writer exists per identity across the entire pool.

### Multi-thread deployments: let the database arbitrate

When you cannot route by identity (mixed workloads, legacy routing), let the database enforce the invariant. Every `LedgerActor` writes to the same Postgres row; Postgres unique constraints and optimistic versioning catch cross-thread conflicts. The actor still serialises within a thread. Conflicts surface as `WriterConflictException` or `OptimisticLockException` at the persistence layer.

Use routing when the hot path is write-heavy and you want to eliminate cross-thread conflicts entirely. Use database arbitration when routing is impractical and you can tolerate the occasional retry.

### Choosing a persistence shape

Two persistence models back single-writer aggregates. Pick one per entity and do not mix them.

**Event-sourced** — state is rebuilt from an append-only event log. Use this when the audit trail is part of the product (financial transactions, legal traceability), when you need multiple projections, or when events are smaller than state and there are many of them:

```php title="src/Actor/WalletActor.php"
EventSourcedBehavior::create(
    persistenceId: PersistenceId::of('Wallet', $ownerId),
    emptyState: new WalletState(balance: Money::zero()),
    commandHandler: $commandHandler,
    eventHandler: $eventHandler,
)
->withEventStore($eventStore)
->withSnapshotStrategy(SnapshotStrategy::everyN(100))
->toBehavior();
```

**Durable state** — state is the database row. Use this when you are modelling an existing Doctrine entity, the current state is what matters and you do not need history, or other read paths already use the ORM:

```php title="src/Actor/LedgerActor.php"
EntityRefFactory::for(new ActorSystemSpawner($system), WalletLedger::class)
    ->using(new DefaultEntityManagerFactory($ormConfig))
    ->withConnectionLifecycle($pool->take(...), $pool->release(...))
    ->withReplayPolicy(new CreateIfMissing(fn(string $id) => new WalletLedger($id)))
    ->withReceiveTimeout(Duration::seconds(60))
    ->handle($commandHandler)
    ->build();
```

### Sealing the command protocol

Pair single-writer with a sealed command interface to make wrong-actor sends a compile-time error:

```php title="src/Domain/LedgerCommand.php"
interface LedgerCommand {}
final readonly class RecordLedger implements LedgerCommand { /* ... */ }
final readonly class CorrectEntry  implements LedgerCommand { /* ... */ }
```

The handler types against `LedgerCommand`, not `object`. Sending a `Deposit` (which `WalletActor` handles) to `LedgerActor` is now a static error. Adding a new `LedgerCommand` without a matching handler arm is also a static error — no `default` branch in the `match` means Psalm catches the gap.

## Caveats

- Single-writer guarantees are scoped to one `ActorSystem`. Distributing across threads or machines requires explicit routing or database-level conflict detection.
- Passivation and restart both reconstruct actor state from the backing store. Ensure the persistence layer is configured before the actor handles its first message.
- Do not spawn multiple actors for the same aggregate id. `EntityRefFactory::of($id)` prevents this by caching the ref; if you bypass the factory you own the invariant.

## Related

- [Persistence overview](../persistence/overview.md)
- [Scaling overview](../scaling/overview.md)
- [Message design](message-design.md)
