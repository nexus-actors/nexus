---
sidebar_position: 10
title: nexus-persistence-dbal
---

# nexus-persistence-dbal

Doctrine DBAL adapter for Nexus persistence -- SQL-backed stores using
parameterized queries. Supports any database supported by DBAL (SQLite,
PostgreSQL, MySQL, etc.).

**Composer:** `monadial/nexus-persistence-dbal`

**Namespace:** `Monadial\Nexus\Persistence\Dbal\`

**Dependencies:** `doctrine/dbal ^4.0`

## Classes

| Class | Description |
|---|---|
| `DbalEventStore` | `EventStore` implementation using DBAL. Constructor: `Connection`. |
| `DbalSnapshotStore` | `SnapshotStore` implementation using DBAL. Constructor: `Connection`. |
| `DbalDurableStateStore` | `DurableStateStore` implementation using DBAL. Constructor: `Connection`. |
| `PersistenceSchemaManager` | Creates and drops database tables. Constructor: `Connection`. Methods: `createSchema()`, `dropSchema()`. Lives in `Schema\` sub-namespace. |

## Table schema

The `PersistenceSchemaManager` creates the following tables:

### nexus_event_journal

| Column | Type | Notes |
|---|---|---|
| `persistence_id` | `VARCHAR(255)` | Primary key (composite) |
| `sequence_nr` | `BIGINT` | Primary key (composite) |
| `event_type` | `VARCHAR(255)` | |
| `event_data` | `TEXT` | |
| `metadata` | `TEXT` | Nullable |
| `timestamp` | `DATETIME` | Immutable |

Index: `idx_event_journal_pid` on `persistence_id`.

### nexus_snapshot_store

| Column | Type | Notes |
|---|---|---|
| `persistence_id` | `VARCHAR(255)` | Primary key (composite) |
| `sequence_nr` | `BIGINT` | Primary key (composite) |
| `state_type` | `VARCHAR(255)` | |
| `state_data` | `TEXT` | |
| `timestamp` | `DATETIME` | Immutable |

### nexus_durable_state

| Column | Type | Notes |
|---|---|---|
| `persistence_id` | `VARCHAR(255)` | Primary key |
| `revision` | `BIGINT` | |
| `state_type` | `VARCHAR(255)` | |
| `state_data` | `TEXT` | |
| `timestamp` | `DATETIME` | Immutable |

## Usage

```php
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Persistence\Dbal\DbalEventStore;
use Monadial\Nexus\Persistence\Dbal\DbalSnapshotStore;
use Monadial\Nexus\Persistence\Dbal\DbalDurableStateStore;
use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => 'nexus.db']);

// Create tables (idempotent -- skips existing tables)
(new PersistenceSchemaManager($connection))->createSchema();

$eventStore = new DbalEventStore($connection);
$snapshotStore = new DbalSnapshotStore($connection);
$durableStateStore = new DbalDurableStateStore($connection);
```
