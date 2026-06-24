---
sidebar_position: 10
title: nexus-persistence-dbal
related:
  - packages/persistence
  - packages/persistence-doctrine
  - persistence/event-sourcing
---

# nexus-persistence-dbal

Doctrine DBAL adapter for Nexus persistence — SQL-backed event store, snapshot store, and durable state store using parameterized queries.

## What's in this package

- `DbalEventStore` — `EventStore` implementation; throws `ConcurrentModificationException` on duplicate sequence numbers
- `DbalSnapshotStore` — `SnapshotStore` implementation
- `DbalDurableStateStore` — `DurableStateStore` implementation; uses optimistic locking via `WHERE version = ?`
- `PersistenceSchemaManager` — creates and drops the three required tables; idempotent

## Install

```bash
composer require nexus-actors/persistence-dbal
```

## Quick example

```php title="src/Persistence/DbalSetup.php"
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Persistence\Dbal\DbalEventStore;
use Monadial\Nexus\Persistence\Dbal\DbalSnapshotStore;
use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;

$conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => 'nexus.db']);

(new PersistenceSchemaManager($conn))->createSchema();

$eventStore    = new DbalEventStore($conn);
$snapshotStore = new DbalSnapshotStore($conn);
```

All three stores stamp each persisted envelope with the actor system's `writer_id` (ULID) for writer-conflict detection. Pass a custom `MessageSerializer` as the second constructor argument to override the default `PhpNativeSerializer`.

## See also

- [nexus-persistence](./persistence.md) — `EventSourcedBehavior`, `DurableStateBehavior`, and store interfaces
- [nexus-persistence-doctrine](./persistence-doctrine.md) — ORM alternative using `EntityManagerInterface`
- [Persistence / overview](../persistence/overview.md) — conceptual guide
