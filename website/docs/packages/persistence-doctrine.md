---
sidebar_position: 11
title: nexus-persistence-doctrine
---

# nexus-persistence-doctrine

Doctrine ORM adapter for Nexus persistence -- entity-based stores that use
`EntityManager` for all database operations. Shares the same table schema as
`nexus-persistence-dbal`.

**Composer:** `monadial/nexus-persistence-doctrine`

**Namespace:** `Monadial\Nexus\Persistence\Doctrine\`

**Dependencies:** `doctrine/orm ^3.0`

## Store classes

| Class | Description |
|---|---|
| `DoctrineEventStore` | `EventStore` implementation using `EntityManagerInterface`. Constructor: `EntityManagerInterface`. |
| `DoctrineSnapshotStore` | `SnapshotStore` implementation using `EntityManagerInterface`. Constructor: `EntityManagerInterface`. |
| `DoctrineDurableStateStore` | `DurableStateStore` implementation using `EntityManagerInterface`. Constructor: `EntityManagerInterface`. |

## ORM entities

`Monadial\Nexus\Persistence\Doctrine\Entity\`

| Class | Description |
|---|---|
| `EventEntry` | ORM entity mapped to `nexus_event_journal`. Properties: `persistenceId`, `sequenceNr`, `eventType`, `eventData`, `metadata`, `timestamp`. |
| `SnapshotEntry` | ORM entity mapped to `nexus_snapshot_store`. Properties: `persistenceId`, `sequenceNr`, `stateType`, `stateData`, `timestamp`. |
| `DurableStateEntry` | ORM entity mapped to `nexus_durable_state`. Properties: `persistenceId`, `revision`, `stateType`, `stateData`, `timestamp`. |

## Usage

```php
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Persistence\Doctrine\DoctrineEventStore;
use Monadial\Nexus\Persistence\Doctrine\DoctrineSnapshotStore;
use Monadial\Nexus\Persistence\Doctrine\DoctrineDurableStateStore;

$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/vendor/monadial/nexus-persistence-doctrine/src/Entity'],
    isDevMode: true,
);

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => 'nexus.db']);
$em = new EntityManager($connection, $config);

$eventStore = new DoctrineEventStore($em);
$snapshotStore = new DoctrineSnapshotStore($em);
$durableStateStore = new DoctrineDurableStateStore($em);
```
