<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;

final class PersistenceSchemaManager
{
    public function __construct(private readonly Connection $connection) {}

    public function createSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        foreach ($this->getTables() as $table) {
            if (!$schemaManager->tablesExist([$table->getName()])) {
                $schemaManager->createTable($table);
            }
        }
    }

    public function dropSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        foreach (['nexus_event_journal', 'nexus_snapshot_store', 'nexus_durable_state', 'nexus_persistence_lock'] as $tableName) {
            if ($schemaManager->tablesExist([$tableName])) {
                $schemaManager->dropTable($tableName);
            }
        }
    }

    /** @return list<Table> */
    private function getTables(): array
    {
        $schema = new Schema();

        // Event journal
        $events = $schema->createTable('nexus_event_journal');
        $events->addColumn('persistence_id', 'string', ['length' => 255]);
        $events->addColumn('sequence_nr', 'bigint');
        $events->addColumn('event_type', 'string', ['length' => 255]);
        $events->addColumn('event_data', 'text');
        $events->addColumn('metadata', 'text', ['notnull' => false]);
        $events->addColumn('timestamp', 'datetime_immutable');
        $events->setPrimaryKey(['persistence_id', 'sequence_nr']);
        $events->addIndex(['persistence_id'], 'idx_event_journal_pid');

        // Snapshot store
        $snapshots = $schema->createTable('nexus_snapshot_store');
        $snapshots->addColumn('persistence_id', 'string', ['length' => 255]);
        $snapshots->addColumn('sequence_nr', 'bigint');
        $snapshots->addColumn('state_type', 'string', ['length' => 255]);
        $snapshots->addColumn('state_data', 'text');
        $snapshots->addColumn('timestamp', 'datetime_immutable');
        $snapshots->setPrimaryKey(['persistence_id', 'sequence_nr']);

        // Durable state
        $durableState = $schema->createTable('nexus_durable_state');
        $durableState->addColumn('persistence_id', 'string', ['length' => 255]);
        $durableState->addColumn('version', 'bigint');
        $durableState->addColumn('state_type', 'string', ['length' => 255]);
        $durableState->addColumn('state_data', 'text');
        $durableState->addColumn('timestamp', 'datetime_immutable');
        $durableState->setPrimaryKey(['persistence_id']);

        // Pessimistic lock
        $lock = $schema->createTable('nexus_persistence_lock');
        $lock->addColumn('persistence_id', 'string', ['length' => 255]);
        $lock->setPrimaryKey(['persistence_id']);

        return $schema->getTables();
    }
}
