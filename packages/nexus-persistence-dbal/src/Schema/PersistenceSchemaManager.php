<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;

/** @psalm-api */
final class PersistenceSchemaManager
{
    public function __construct(private readonly Connection $connection) {}

    public function createSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        foreach ($this->getTables() as $table) {
            if (!$schemaManager->tablesExist([$table->getObjectName()->toString()])) {
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
        $events->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames('persistence_id', 'sequence_nr')
                ->create(),
        );
        $events->addIndex(['persistence_id'], 'idx_event_journal_pid');

        // Snapshot store
        $snapshots = $schema->createTable('nexus_snapshot_store');
        $snapshots->addColumn('persistence_id', 'string', ['length' => 255]);
        $snapshots->addColumn('sequence_nr', 'bigint');
        $snapshots->addColumn('state_type', 'string', ['length' => 255]);
        $snapshots->addColumn('state_data', 'text');
        $snapshots->addColumn('timestamp', 'datetime_immutable');
        $snapshots->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames('persistence_id', 'sequence_nr')
                ->create(),
        );

        // Durable state
        $durableState = $schema->createTable('nexus_durable_state');
        $durableState->addColumn('persistence_id', 'string', ['length' => 255]);
        $durableState->addColumn('version', 'bigint');
        $durableState->addColumn('state_type', 'string', ['length' => 255]);
        $durableState->addColumn('state_data', 'text');
        $durableState->addColumn('timestamp', 'datetime_immutable');
        $durableState->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames('persistence_id')
                ->create(),
        );

        // Pessimistic lock
        $lock = $schema->createTable('nexus_persistence_lock');
        $lock->addColumn('persistence_id', 'string', ['length' => 255]);
        $lock->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames('persistence_id')
                ->create(),
        );

        return $schema->getTables();
    }
}
