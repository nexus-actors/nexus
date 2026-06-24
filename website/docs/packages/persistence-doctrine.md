---
sidebar_position: 11
title: nexus-persistence-doctrine
related:
  - packages/persistence
  - packages/persistence-dbal
  - persistence/event-sourcing
---

# nexus-persistence-doctrine

Doctrine ORM adapter for Nexus persistence — entity-based event store, snapshot store, and durable state store sharing the same table schema as `nexus-persistence-dbal`.

## What's in this package

- `DoctrineEventStore` — `EventStore` via `EntityManagerInterface`; throws `ConcurrentModificationException` on duplicate sequence numbers
- `DoctrineSnapshotStore` — `SnapshotStore` via `EntityManagerInterface`
- `DoctrineDurableStateStore` — `DurableStateStore` via `EntityManagerInterface`; uses Doctrine `#[ORM\Version]` for optimistic locking
- ORM entities: `EventEntry`, `SnapshotEntry`, `DurableStateEntry` — mapped to the three persistence tables

## Install

```bash
composer require nexus-actors/persistence-doctrine
```

## Quick example

```php title="src/Persistence/DoctrineSetup.php"
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Persistence\Doctrine\DoctrineEventStore;
use Monadial\Nexus\Persistence\Doctrine\DoctrineSnapshotStore;

$ormConfig = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/vendor/nexus-actors/persistence-doctrine/src/Entity'],
    isDevMode: true,
);
$conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => 'nexus.db']);
$em   = new EntityManager($conn, $ormConfig);

$eventStore    = new DoctrineEventStore($em);
$snapshotStore = new DoctrineSnapshotStore($em);
```

Pass a custom `MessageSerializer` as the second constructor argument to override the default `PhpNativeSerializer`. The schema is identical to `nexus-persistence-dbal`; use `PersistenceSchemaManager` from that package to create the tables.

## See also

- [nexus-persistence](./persistence.md) — `EventSourcedBehavior`, `DurableStateBehavior`, and store interfaces
- [nexus-persistence-dbal](./persistence-dbal.md) — DBAL alternative using raw parameterized queries
- [Persistence / overview](../persistence/overview.md) — conceptual guide
