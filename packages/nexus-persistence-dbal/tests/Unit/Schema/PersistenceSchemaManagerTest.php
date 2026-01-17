<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal\Tests\Unit\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersistenceSchemaManager::class)]
final class PersistenceSchemaManagerTest extends TestCase
{
    private Connection $connection;
    private PersistenceSchemaManager $schemaManager;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->schemaManager = new PersistenceSchemaManager($this->connection);
    }

    #[Test]
    public function createSchemaCreatesAllThreeTables(): void
    {
        $this->schemaManager->createSchema();

        $sm = $this->connection->createSchemaManager();
        self::assertTrue($sm->tablesExist(['nexus_event_journal']));
        self::assertTrue($sm->tablesExist(['nexus_snapshot_store']));
        self::assertTrue($sm->tablesExist(['nexus_durable_state']));
    }

    #[Test]
    public function createSchemaIsIdempotent(): void
    {
        $this->schemaManager->createSchema();
        $this->schemaManager->createSchema();

        $sm = $this->connection->createSchemaManager();
        self::assertTrue($sm->tablesExist(['nexus_event_journal']));
        self::assertTrue($sm->tablesExist(['nexus_snapshot_store']));
        self::assertTrue($sm->tablesExist(['nexus_durable_state']));
    }

    #[Test]
    public function eventJournalTableHasCorrectColumns(): void
    {
        $this->schemaManager->createSchema();

        $columns = $this->connection->createSchemaManager()
            ->listTableColumns('nexus_event_journal');

        $columnNames = array_keys($columns);
        self::assertContains('persistence_id', $columnNames);
        self::assertContains('sequence_nr', $columnNames);
        self::assertContains('event_type', $columnNames);
        self::assertContains('event_data', $columnNames);
        self::assertContains('metadata', $columnNames);
        self::assertContains('timestamp', $columnNames);
    }

    #[Test]
    public function eventJournalMetadataColumnIsNullable(): void
    {
        $this->schemaManager->createSchema();

        $columns = $this->connection->createSchemaManager()
            ->listTableColumns('nexus_event_journal');

        self::assertFalse($columns['metadata']->getNotnull());
    }

    #[Test]
    public function snapshotStoreTableHasCorrectColumns(): void
    {
        $this->schemaManager->createSchema();

        $columns = $this->connection->createSchemaManager()
            ->listTableColumns('nexus_snapshot_store');

        $columnNames = array_keys($columns);
        self::assertContains('persistence_id', $columnNames);
        self::assertContains('sequence_nr', $columnNames);
        self::assertContains('state_type', $columnNames);
        self::assertContains('state_data', $columnNames);
        self::assertContains('timestamp', $columnNames);
    }

    #[Test]
    public function durableStateTableHasCorrectColumns(): void
    {
        $this->schemaManager->createSchema();

        $columns = $this->connection->createSchemaManager()
            ->listTableColumns('nexus_durable_state');

        $columnNames = array_keys($columns);
        self::assertContains('persistence_id', $columnNames);
        self::assertContains('revision', $columnNames);
        self::assertContains('state_type', $columnNames);
        self::assertContains('state_data', $columnNames);
        self::assertContains('timestamp', $columnNames);
    }

    #[Test]
    public function dropSchemaRemovesAllTables(): void
    {
        $this->schemaManager->createSchema();
        $this->schemaManager->dropSchema();

        $sm = $this->connection->createSchemaManager();
        self::assertFalse($sm->tablesExist(['nexus_event_journal']));
        self::assertFalse($sm->tablesExist(['nexus_snapshot_store']));
        self::assertFalse($sm->tablesExist(['nexus_durable_state']));
    }

    #[Test]
    public function dropSchemaIsIdempotent(): void
    {
        $this->schemaManager->createSchema();
        $this->schemaManager->dropSchema();
        $this->schemaManager->dropSchema();

        $sm = $this->connection->createSchemaManager();
        self::assertFalse($sm->tablesExist(['nexus_event_journal']));
    }
}
