---
title: Durable state
related:
  - persistence/event-sourcing
  - persistence/stores
  - persistence/testing
  - reference/classes/durable-state-behavior
---

# Durable state

:::warning Experimental
The persistence layer is experimental and pre-1.0. APIs and storage formats may change in breaking ways between releases.
:::

`DurableStateBehavior` persists the actor's full current state as a single snapshot on every write, with no event history retained. Recovery loads the latest snapshot and the actor is immediately ready — no replay loop.

## The design

The durable state model trades auditability for simplicity. There are no events, no event handlers, no sequence numbers to track. The command handler returns a `DurableEffect` that either persists the new state or replies without persisting.

```php title="src/Aggregates/UserProfileActor.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableEffect;
use Monadial\Nexus\Persistence\State\DurableStateBehavior;

$behavior = DurableStateBehavior::create(
    PersistenceId::of('UserProfile', $userId),
    new UserProfile(),
    static fn (UserProfile $state, ActorContext $ctx, object $cmd): DurableEffect => match (true) {
        $cmd instanceof UpdateEmail   => DurableEffect::persist($state->withEmail($cmd->email)),
        $cmd instanceof UpdateName    => DurableEffect::persist($state->withName($cmd->name)),
        $cmd instanceof GetProfile    => DurableEffect::reply($cmd->replyTo, $state),
        default => DurableEffect::unhandled(),
    },
)
    ->withStateStore($stateStore)
    ->toBehavior();
```

### `DurableEffect`

The command handler returns a `DurableEffect`:

- `DurableEffect::persist($newState)` — write the entire new state object to the store.
- `DurableEffect::none()` — acknowledge the command without writing; any chained side-effects are dropped.
- `DurableEffect::reply($to, $message)` — send a reply as the sole effect (use this for read queries).
- `DurableEffect::stash()` — buffer the command; replay after `$ctx->unstashAll()`.
- `DurableEffect::stop()` — stop the actor.
- `DurableEffect::unhandled()` — route to dead letters.

Chain `->thenReply($to, fn($state) => $msg)` or `->thenRun(fn($state) => ...)` to attach side-effects that execute after the state is durably stored. These hooks run only on the persist path — chained on any other effect (including `DurableEffect::none()`) they are silently dropped.

## Comparison with event sourcing

| Property | Durable state | Event sourcing |
|---|---|---|
| What is stored | Latest state snapshot | Full event history |
| Recovery speed | Fast — load one record | Slower — replay all events |
| Audit trail | None | Full; every change is an event |
| Storage growth | Bounded — one row per entity | Unbounded without retention policy |
| Read-model projection | Not possible | Possible — replay into any shape |
| Snapshot required | Implicit (every write) | Optional, accelerates recovery |
| `ReplayFilter` / writer conflict | Same `writerId` stamping | Same `writerId` stamping |
| Best for | User preferences, settings, caches | Orders, payments, ledgers, anything that needs history |

## When to use durable state

Use `DurableStateBehavior` when:

- The actor represents mutable configuration or preferences with no need for history.
- You want faster recovery and lower storage overhead than event sourcing provides.
- The domain does not require audit trails or temporal queries.

Use `EventSourcedBehavior` when the command history matters — for example, financial transactions, order processing, or anything audited by compliance requirements.

## `DurableStateStore`

`DurableStateBehavior` requires a `DurableStateStore`. Three implementations are available:

- `InMemoryDurableStateStore` — for tests; state is lost on process restart.
- `DbalDurableStateStore` — DBAL-backed, writes to a single `nexus_durable_state` table.
- `DoctrineDurableStateStore` — Doctrine ORM-backed.

<!-- verify:skip: requires a running database connection -->
```php title="src/Bootstrap/PersistenceSetup.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\Dbal\DbalDurableStateStore;

$store = new DbalDurableStateStore($connection);

$behavior = DurableStateBehavior::create($persistenceId, new UserProfile(), $commandHandler)
    ->withStateStore($store)
    ->toBehavior();
```

## See also

- [Event sourcing](./event-sourcing.md) — full event history, replay-based recovery.
- [Stores](./stores.md) — in-memory, DBAL, and Doctrine store implementations.
- [Testing](./testing.md) — testing durable-state actors with `InMemoryDurableStateStore`.
