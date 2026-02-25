<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Tests\Unit;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Persistence\Doctrine\DoctrineSnapshotStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Uid\Ulid;

#[CoversClass(DoctrineSnapshotStore::class)]
final class DoctrineSnapshotStoreTest extends TestCase
{
    private EntityManagerInterface $em;
    private DoctrineSnapshotStore $store;
    private PersistenceId $id;
    private Ulid $testWriterId;

    #[Test]
    public function saveAndLoad(): void
    {
        $snapshot = $this->makeSnapshot(5);

        $this->store->save($this->id, $snapshot);

        $loaded = $this->store->load($this->id);
        self::assertNotNull($loaded);
        self::assertSame(5, $loaded->sequenceNr);
        self::assertSame(stdClass::class, $loaded->stateType);
        self::assertEquals(500, $loaded->state->total);
        self::assertTrue($this->testWriterId->equals($loaded->writerId));
    }

    #[Test]
    public function loadReturnsLatestSnapshot(): void
    {
        $this->store->save($this->id, $this->makeSnapshot(5));
        $this->store->save($this->id, $this->makeSnapshot(10));
        $this->store->save($this->id, $this->makeSnapshot(15));

        $loaded = $this->store->load($this->id);
        self::assertNotNull($loaded);
        self::assertSame(15, $loaded->sequenceNr);
        self::assertEquals(1500, $loaded->state->total);
    }

    #[Test]
    public function deleteRemovesSnapshotsUpToSequenceNr(): void
    {
        $this->store->save($this->id, $this->makeSnapshot(5));
        $this->store->save($this->id, $this->makeSnapshot(10));
        $this->store->save($this->id, $this->makeSnapshot(15));

        $this->store->delete($this->id, 10);

        $loaded = $this->store->load($this->id);
        self::assertNotNull($loaded);
        self::assertSame(15, $loaded->sequenceNr);
    }

    #[Test]
    public function deleteAllSnapshotsReturnsNull(): void
    {
        $this->store->save($this->id, $this->makeSnapshot(5));
        $this->store->save($this->id, $this->makeSnapshot(10));

        $this->store->delete($this->id, 10);

        self::assertNull($this->store->load($this->id));
    }

    #[Test]
    public function loadReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->store->load($this->id));
    }

    #[Test]
    public function loadReturnsNullForUnknownPersistenceId(): void
    {
        $unknownId = PersistenceId::of('order', 'unknown');

        self::assertNull($this->store->load($unknownId));
    }

    #[Test]
    public function stateIsSerializedAndDeserialized(): void
    {
        $state = new stdClass();
        $state->items = ['item-1', 'item-2'];
        $state->status = 'confirmed';

        $snapshot = new SnapshotEnvelope(
            persistenceId: $this->id,
            sequenceNr: 5,
            state: $state,
            stateType: stdClass::class,
            timestamp: new DateTimeImmutable('2026-01-15 10:00:00'),
            writerId: $this->testWriterId,
        );

        $this->store->save($this->id, $snapshot);

        $loaded = $this->store->load($this->id);
        self::assertNotNull($loaded);
        self::assertEquals(['item-1', 'item-2'], $loaded->state->items);
        self::assertSame('confirmed', $loaded->state->status);
    }

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__ . '/../../src/Entity'],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $this->em = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->store = new DoctrineSnapshotStore($this->em);
        $this->id = PersistenceId::of('order', 'order-1');
        $this->testWriterId = new Ulid();
    }

    private function makeSnapshot(int $sequenceNr): SnapshotEnvelope
    {
        $state = new stdClass();
        $state->total = $sequenceNr * 100;

        return new SnapshotEnvelope(
            persistenceId: $this->id,
            sequenceNr: $sequenceNr,
            state: $state,
            stateType: stdClass::class,
            timestamp: new DateTimeImmutable('2026-01-15 10:00:00'),
            writerId: $this->testWriterId,
        );
    }
}
