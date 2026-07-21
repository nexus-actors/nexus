---
title: Persistence stores
related:
  - persistence/event-sourcing
  - persistence/durable-state
  - persistence/snapshots
  - persistence/testing
---

# Persistence stores

Nexus ships three persistence store implementations: an in-memory store for tests, a DBAL-backed store for production with any Doctrine-supported database, and a Doctrine ORM-backed store for projects that already use Doctrine entities.

## Store overview

| Implementation | Package | Use case |
|---|---|---|
| `InMemoryEventStore` / `InMemorySnapshotStore` / `InMemoryDurableStateStore` | `nexus-persistence` | Unit tests; state is lost on restart |
| `DbalEventStore` / `DbalSnapshotStore` / `DbalDurableStateStore` | `nexus-persistence-dbal` | Production; any DBAL-supported database |
| `DoctrineEventStore` / `DoctrineSnapshotStore` / `DoctrineDurableStateStore` | `nexus-persistence-doctrine` | Projects already using Doctrine ORM |

## In-memory stores

The in-memory stores hold data in PHP arrays for the lifetime of the process. They implement the same interfaces as the durable stores, so test code is structurally identical to production code.

```php title="tests/Integration/OrderActorTest.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;

$eventStore    = new InMemoryEventStore();
$snapshotStore = new InMemorySnapshotStore();

$behavior = EventSourcedBehavior::create($persistenceId, $emptyState, $commandHandler, $eventHandler)
    ->withEventStore($eventStore)
    ->withSnapshotStore($snapshotStore)
    ->withSnapshotStrategy(SnapshotStrategy::everyN(5))
    ->toBehavior();
```

## DBAL stores

Install the DBAL adapter:

```bash
composer require nexus-actors/nexus-persistence-dbal
```

The DBAL stores write to three tables: `nexus_event_journal`, `nexus_snapshot_store`, and `nexus_durable_state`. Create the schema using `PersistenceSchemaManager`:

<!-- verify:skip: requires a running database connection -->
```php title="bin/create-persistence-schema.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;

$schemaManager = new PersistenceSchemaManager($connection);
$schemaManager->createSchema();
```

Wire the stores:

<!-- verify:skip: requires a running database connection -->
```php title="src/Bootstrap/PersistenceSetup.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\Dbal\DbalEventStore;
use Monadial\Nexus\Persistence\Dbal\DbalSnapshotStore;
use Monadial\Nexus\Persistence\Dbal\DbalDurableStateStore;

$eventStore    = new DbalEventStore($connection);
$snapshotStore = new DbalSnapshotStore($connection);
$durableStore  = new DbalDurableStateStore($connection);
```

The DBAL stores require an explicit `MessageSerializer` (there is no default — the previous `PhpNativeSerializer` default allowed unrestricted `unserialize()`, CWE-502). For self-produced, trusted rows use `PhpNativeSerializer::forTrustedData()` or, safer, `new PhpNativeSerializer(allowedClasses: $registry->allClasses())`; for cross-service or untrusted data use the Valinor-based mapper.

## Doctrine stores

Install the Doctrine adapter:

```bash
composer require nexus-actors/nexus-persistence-doctrine
```

The Doctrine stores use three ORM entity classes (`EventEntry`, `SnapshotEntry`, `DurableStateEntry`) mapped to the same table names as the DBAL stores. Wire them with an `EntityManagerInterface`:

<!-- verify:skip: requires a running Doctrine EntityManager -->
```php title="src/Bootstrap/PersistenceSetup.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Persistence\Doctrine\DoctrineEventStore;
use Monadial\Nexus\Persistence\Doctrine\DoctrineSnapshotStore;
use Monadial\Nexus\Persistence\Doctrine\DoctrineDurableStateStore;

$eventStore    = new DoctrineEventStore($entityManager);
$snapshotStore = new DoctrineSnapshotStore($entityManager);
$durableStore  = new DoctrineDurableStateStore($entityManager);
```

:::note
The Doctrine stores use the same table names (`nexus_event_journal`, `nexus_snapshot_store`, `nexus_durable_state`) as the DBAL stores. You can mix store implementations — for example, use `DoctrineEventStore` with `DbalSnapshotStore` — provided both point at the same underlying database.
:::

## Choosing between DBAL and Doctrine

Use the DBAL stores when your project does not use Doctrine ORM or you want minimal dependencies. Use the Doctrine stores when you already have an `EntityManager` configured and want Doctrine's lifecycle events and schema tooling to manage the persistence tables.

## See also

- [Event sourcing](./event-sourcing.md) — wiring stores via `EventSourcedBehavior`.
- [Durable state](./durable-state.md) — wiring `DurableStateStore`.
- [Testing](./testing.md) — using in-memory stores in deterministic tests.
