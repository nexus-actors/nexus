---
sidebar_position: 5
title: EntityBehavior DSL
---

# EntityBehavior DSL

`EntityBehavior` turns a Doctrine entity into the state of an aggregate
actor — no event sourcing required. The entity loads from the DB on
`PreStart`, processes commands, and persists when the command handler
returns `EntityEffect::persist()`.

If you've used `DurableStateBehavior` from the persistence layer, this is
the same shape — just with `EntityManagerInterface::flush()` doing the
work instead of a state store.

## Why it exists

Doctrine entities are natural aggregates: they encapsulate invariants
("an Order can't accept line items after it's paid"), have a clear
identity, and Doctrine already provides the persistence machinery. The
problem is concurrency: two requests modifying the same order interleave,
and optimistic locking forces clients to retry.

`EntityBehavior` solves this by making the actor the single writer:
exactly one actor exists per `(entityClass, id)`, messages are processed
serially, and the entity is the actor's state. Optimistic locking still
exists as a defense against external writers, but inside the actor system
it's not the primary concurrency control.

## Quick start

```php
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;

$behavior = EntityBehavior::create(
    entityClass: Order::class,
    id: $orderId,
    commandHandler: static fn($ctx, object $cmd, Order $order): EntityEffect =>
        match (true) {
            $cmd instanceof AddLineItem => $order->tryAdd($cmd->sku, $cmd->qty)
                ? EntityEffect::persist()->thenReply($cmd->replyTo, fn($o) => new LineAdded($o->total()))
                : EntityEffect::reply($cmd->replyTo, new LineRejected('out of stock')),
            $cmd instanceof Cancel       => EntityEffect::remove()
                ->thenReply($cmd->replyTo, fn($o) => new OrderCancelled()),
            default                      => EntityEffect::same(),
        },
)
    ->withEntityManagerFactory(new DefaultEntityManagerFactory($ormConfig))
    ->withReplayPolicy(new CreateIfMissing(static fn($id): Order => new Order($id)))
    ->withDirectConnection(['driver' => 'pdo_mysql', 'url' => '…'])
    ->toBehavior();

$ref = $system->spawn(Props::fromBehavior($behavior), 'order-' . $orderId);
$ref->tell(new AddLineItem('SKU-42', 2, $replyTo));
```

## `EntityEffect`

The return type of your command handler. Tells the runner what to do
after the handler returns.

### Terminal effects

| Effect | Behavior |
|---|---|
| `EntityEffect::same()` | No DB op. UoW untouched. State stays. |
| `EntityEffect::persist()` | `$em->flush()`. UoW commits whatever you mutated. |
| `EntityEffect::remove()` | `$em->remove($entity); $em->flush()`; actor stops. |
| `EntityEffect::stop()` | Actor stops. **No flush** — pending changes discarded. |
| `EntityEffect::stash()` | Stash current message via `$ctx->stash()` for later unstash. |

### `reply()`

Send a synchronous reply (composable with any kind):

```php
$cmd instanceof GetTotal => EntityEffect::reply($cmd->replyTo, new Total($order->total()))
```

This fires **before** the flush — useful when the reply doesn't depend on
the post-write state.

### `thenRun(Closure $hook)` and `thenReply(ActorRef $to, Closure $build)`

Post-flush hooks. Fire after `$em->flush()` so the entity has its
post-write state (generated IDs, bumped version columns, etc.):

```php
EntityEffect::persist()
    ->thenRun(fn(Order $o) => $logger->info('persisted', ['id' => $o->getId()]))
    ->thenReply($cmd->replyTo, fn(Order $o) => new LineAdded(id: $o->getId(), total: $o->total()))
```

Hooks are skipped for `stop()` (no flush, entity may be inconsistent) but
fire for `remove()` (flush did happen).

## `EntityReplayPolicy`

How the runner loads the entity on `PreStart`.

| Policy | Behavior |
|---|---|
| `FailIfMissing` (default) | `$em->find()` → throws `EntityNotFoundException` if absent → `ActorInitializationException` propagates from `spawn()`. |
| `CreateIfMissing(fn(mixed $id): T $factory)` | `$em->find()`, fall back to `$factory($id)` + `$em->persist($entity)` on miss. |
| `OnDemand` | Skip load on start; runner uses `$em->find()` on the first command instead. |

### Choosing

- **`FailIfMissing`** for aggregates created by an explicit command somewhere else (e.g. `CreateOrder` use case). You don't want to silently create an Order when a stale message references a deleted one.
- **`CreateIfMissing`** for "spawn on first access" — user sessions, shopping carts, per-entity counters.
- **`OnDemand`** for very-many-rarely-used entities — spawning an actor is cheap; loading rows is expensive. The DB row must already exist by the time the first command arrives.

## Dedicated, non-pooled EM

Each `EntityBehavior` actor gets a **dedicated** EM constructed from
`EntityManagerFactory` — **not** from `EntityManagerPool`. Reasoning:

- Actor lifetime ≠ request lifetime. A hot entity actor may run for
  minutes or hours. Pooling would either pin a pool slot forever or
  require swapping EMs (which loses UoW identity for the tracked entity).
- The connection is held for the whole actor lifetime.

**This is a real cost.** An app with 10k hot entity actors needs ≥10k
DB connections. Mitigations:

- **Passivation**: configure `ReceiveTimeout`; on the signal return
  `Behavior::stopped()`. Idle actors release their connection.
- **Bounded active count**: route all `EntityBehavior` traffic through a
  router actor that maintains a fixed-size LRU of spawned aggregates,
  stopping the LRU-evicted one when adding a new one.

## Passivation

`EntityBehavior` actors hold their EM and Connection for their whole lifetime.
For hot entities that's fine; for the long tail it's expensive. Opt into
idle-passivation via `withReceiveTimeout`:

```php
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;

$behavior = EntityBehavior::create(Order::class, $id, $handler)
    ->withEntityManagerFactory($emFactory)
    ->withConnectionSource($connSource)
    ->withReceiveTimeout(Duration::seconds(120))   // passivate after 2 min idle
    ->toBehavior();
```

After 120s without messages, the actor self-terminates. The runner's `PostStop`
handler closes the EM and Connection automatically -- no manual cleanup needed.
The next call to `EntityRefFactory::of($id)` notices the cached ref is dead,
spawns a fresh actor, reloads the entity from DB via the replay policy, and
processes the incoming message -- transparent to the caller.

`EntityRefFactoryBuilder` has a matching `withReceiveTimeout(Duration)` that
forwards the timeout to every spawned actor:

```php
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSystemSpawner;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;

$factory = EntityRefFactory::for(new ActorSystemSpawner($system), Order::class)
    ->using($emFactory)
    ->withConnectionSource($connSource)
    ->withReceiveTimeout(Duration::seconds(120))    // applied to every spawned actor
    ->handle($commandHandler)
    ->build();
```

### Cost trade-off

- **Pinned connection vs cold-start latency.** Without passivation, every active
  aggregate pins a connection. With passivation, only *concurrently-active*
  aggregates have connections -- but the first message after passivation pays a
  connection-open + EM-build + entity-load cost (typically tens of milliseconds
  against Postgres+TLS).
- **In-flight messages during the passivation → rehydration gap go to dead
  letters.** For most write paths this is acceptable -- clients retry. For
  high-stakes commands, send via `ask()` so the per-message timeout surfaces the
  failure rather than silently dropping it.

### Caveats

- **Use a generous timeout in production.** Sub-second timeouts amplify the
  rehydration cost relative to the work done per message. 60s--10min is the
  sensible range for most aggregates.
- **`EntityRefFactory` caches across the whole `ActorSystem`.** If two factories
  are constructed against the same system pointing at the same entity class,
  they'll race on actor names. Use one factory per `(system, entityClass)`.

## `EntityRefFactory`

Spawn-once-and-cache by entity id. Enforces single-writer
per `(entityClass, id)` within an `ActorSystem`.

```php
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSystemSpawner;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;

$factory = EntityRefFactory::for(new ActorSystemSpawner($system), Order::class)
    ->using($emFactory)
    ->withConnectionSource(fn() => DriverManager::getConnection($connParams))
    ->withReplayPolicy(new CreateIfMissing(fn($id) => new Order($id)))
    ->handle($commandHandler)
    ->build();

// First call spawns the actor; subsequent calls return the cached ref.
$factory->of(42)->tell(new AddLineItem(...));
$factory->of(42)->ask(fn($r) => new GetTotal($r), Duration::seconds(2));
```

Derived actor names use `--` as separator: `App.Order--42`. The pattern is
deterministic and stable; combined with the actor system's
`ActorNameExistsException` on duplicate spawns, you get a real
single-writer guarantee.

Under `nexus-worker-pool-swoole`, `(Order--42)` routes through the
`ConsistentHashRing` to a specific worker thread, so single-writer holds
cluster-wide too.

### `ActorSystemSpawner`

Thin adapter that wraps an `ActorSystem` as an `ActorSpawner`. Needed
because `ActorSystem` is `final` and doesn't directly implement the
interface — the indirection makes `EntityRefFactory` unit-testable.

## `EntityConflictException`

Wraps Doctrine's `OptimisticLockException`. The runner catches the lock
exception during `$em->flush()` and rethrows as `EntityConflictException`
with the entity class + id pre-populated.

Default supervision behavior: restart with reload. On restart the runner
opens a fresh EM, calls the replay policy again, and reprocesses the
stashed-or-discarded message. Configure your supervision strategy at
`Props::withSupervision(...)` for different retry/backoff semantics.

## Failure modes

| Scenario | Behavior |
|---|---|
| Entity not found + `FailIfMissing` | `ActorInitializationException` from `spawn()` — fail-fast. |
| DB unavailable on PreStart | `ActorInitializationException` — wrap with `exponentialBackoff` supervision. |
| Connection lost mid-message | Handler throws → message goes to dead letters → supervision triggers restart. |
| Optimistic-lock conflict | `EntityConflictException` → restart with reload (default). |
| EM closes after flush failure | Actor restart mandatory — Doctrine forces this regardless of supervision directive. |

## Worked example

A counter aggregate. Schema is created out-of-band; the actor loads its
state on start, adds when told, persists, and shuts down with the
verified DB value.

```php
#[Entity]
#[Table(name: 'counters')]
final class Counter
{
    #[Id] #[Column] public string $id;
    #[Column] public int $value = 0;

    public function tryAdd(int $delta): bool
    {
        $this->value += $delta;
        return true;
    }
}

final readonly class Add { public function __construct(public int $delta) {} }

$behavior = EntityBehavior::create(
    entityClass: Counter::class,
    id: 'c-1',
    commandHandler: static fn($ctx, object $msg, Counter $c): EntityEffect =>
        match (true) {
            $msg instanceof Add => $c->tryAdd($msg->delta)
                ? EntityEffect::persist()
                : EntityEffect::same(),
            default => EntityEffect::same(),
        },
)
    ->withEntityManagerFactory(new DefaultEntityManagerFactory($ormConfig))
    ->withReplayPolicy(new CreateIfMissing(fn(string $id): Counter => new Counter($id)))
    ->withConnectionSource(fn(): Connection => DriverManager::getConnection($connParams))
    ->toBehavior();

$ref = $system->spawn(Props::fromBehavior($behavior), 'counter-1');
$ref->tell(new Add(3));
$ref->tell(new Add(7));

// after shutdown, verify in DB: counter c-1 has value=10
```

This is the actual happy-path integration test the package ships with —
copy and adapt.

## Psalm hooks

Two Psalm rules in `nexus-psalm` keep this safe at type-check time:

- **`EntityBehaviorReturnTypeProvider`** — infers
  `EntityBehaviorBuilder<T, C>` from `EntityBehavior::create($entityClass, $id, $closure)`
  so your command handler's closure params type-check.
- **`MissingTransactionalDeclarationRule`** — flags `#[Transactional]`
  handlers that don't declare a `Connection` or `EntityManagerInterface`
  parameter.

See [`nexus-psalm`](../packages/psalm.md) for the full hook list.
