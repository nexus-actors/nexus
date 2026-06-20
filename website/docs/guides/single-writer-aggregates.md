---
sidebar_position: 3
title: Single-writer aggregates
---

# Single-writer aggregates

> *"How do I make sure two simultaneous deposits to the same wallet
> don't fight each other?"*

You give each aggregate a single in-process actor. The mailbox
serialises every write for that id. No row locks, no `version` columns
to retry, no compensation logic for lost updates.

## The rule

> **One actor per aggregate identity, system-wide. The actor is the
> only thing that ever writes that aggregate.**

If you can hold to that rule, every concurrency problem on that
aggregate evaporates. Two concurrent HTTP requests for the same
wallet enqueue into the same mailbox; the actor processes them one at
a time; the second one sees the state the first one left behind.

## The mechanism

`EntityRefFactory::of($id)` enforces single-writer for you.

```php
$ledgers = LedgerActor::factory($system, $ormConfig, $connParams);

// First call: spawns a new LedgerActor for owner 'alice'.
// Subsequent calls: return the cached ref.
// Even from different HTTP handlers, the same id → same actor.
$ledgers->of('alice')->tell(new RecordLedger(LedgerKind::Deposit, 12345));
$ledgers->of('alice')->tell(new RecordLedger(LedgerKind::Withdraw,  500));
```

Both `tell()`s land in the same mailbox in the order the worker thread
saw them. The actor processes them in that order. The
running-total invariants on `WalletLedger` hold without a single
`SELECT … FOR UPDATE`.

## What about across threads / machines?

This is the question that catches people out. "Single-writer" means
within one `ActorSystem`. If you run four worker threads, each with its
own `ActorSystem`, then alice's wallet has FOUR potential writers —
one per thread.

Two answers:

**1. Make Postgres the source of truth.** The wallet-app does this:
every `LedgerActor` writes to the same Postgres row, and Postgres'
unique constraints + optimistic versioning catch the cross-thread
conflict. The actor still serialises within a thread; conflicts only
ever happen across threads, and the `WriterConflictException` /
`OptimisticLockException` paths in [Single-writer
persistence](../persistence/overview.md#single-writer-guarantee)
take it from there.

**2. Route the same id to the same thread.** The
[Worker Pool](../scaling/overview.md) does exactly this with a
consistent hash ring. Owner id `alice` hashes to thread 2, owner id
`bob` hashes to thread 0 — and *every* request for alice lands on
thread 2 because both the HTTP router and the worker-to-worker
transport use the same ring. Now there is genuinely only one writer
per id across the cluster.

The wallet-app uses (1) because it boots a per-thread `ActorSystem`
and lets Postgres mediate; production deployments where the hot path
is *write* should use (2) so the actor stays in front of Postgres.

## The two persistence shapes

Nexus gives you two ways to back a single-writer aggregate:

### Event-sourced

State is rebuilt from an append-only log. The wallet-app's
`WalletActor` (`POST /wallet/deposit`) uses this — every command emits
a `MoneyDeposited` / `MoneyWithdrawn` event, the actor's
`eventHandler` folds them into a fresh `WalletState`.

Use this when:
- The audit trail is part of the product (financial transactions,
  legal traceability).
- You may add projections later (reporting, search, denormalised
  reads).
- Events are smaller than state and there are many of them.

```php
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

### Durable state (EntityBehavior)

State is the database row. The wallet-app's `LedgerActor`
(`POST /wallet/ledger/record`) uses this — `WalletLedger` IS the
state, and `flush()` writes whatever the command handler mutated.

Use this when:
- You're modelling something that already exists as a Doctrine
  entity.
- The current state is what you care about; you don't need the
  history.
- Other read paths use the ORM and you want a single source of truth.

```php
EntityRefFactory::for(new ActorSystemSpawner($system), WalletLedger::class)
    ->using(new DefaultEntityManagerFactory($ormConfig))
    ->withConnectionLifecycle($pool->take(...), $pool->release(...))
    ->withReplayPolicy(new CreateIfMissing(fn(string $id) => new WalletLedger($id)))
    ->withReceiveTimeout(Duration::seconds(60))
    ->handle($commandHandler)
    ->build();
```

Don't mix the two for the same aggregate. Pick one per entity.

## What about commands the aggregate doesn't recognise?

Sealed command marker. The wallet-app defines:

```php
interface LedgerCommand {}
final readonly class RecordLedger implements LedgerCommand { … }
```

…and the actor's handler types against `LedgerCommand`, not `object`.
Sending a `Deposit` (which the WalletActor handles, but the
LedgerActor doesn't) is now a compile-time error. Adding a new
`LedgerCommand` without a `match` arm is also a compile-time error
(no `default` branch). The protocol is closed.

```php
private static function commandHandler(): Closure
{
    return static fn(
        ActorContext $ctx,
        LedgerCommand $cmd,
        WalletLedger $ledger,
    ): EntityEffect => match (true) {
        $cmd instanceof RecordLedger => self::applyAndPersist($ledger, $cmd),
    };
}
```

Use this pattern for every aggregate that has more than one command.
