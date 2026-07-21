<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Tests\Unit;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Persistence\Doctrine\DoctrineDurableStateStore;
use Monadial\Nexus\Persistence\Exception\ConcurrentModificationException;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Uid\Ulid;

#[CoversClass(DoctrineDurableStateStore::class)]
final class DoctrineDurableStateStoreTest extends TestCase
{
    private EntityManagerInterface $em;
    private DoctrineDurableStateStore $store;
    private PersistenceId $id;
    private Ulid $testWriterId;

    #[Test]
    public function upsertAndGet(): void
    {
        $envelope = $this->makeState(1, 42);

        $this->store->upsert($this->id, $envelope);

        $loaded = $this->store->get($this->id);
        self::assertNotNull($loaded);
        self::assertSame(1, $loaded->version);
        self::assertSame(stdClass::class, $loaded->stateType);
        self::assertEquals(42, $loaded->state->value);
        self::assertTrue($this->testWriterId->equals($loaded->writerId));
    }

    #[Test]
    public function upsertOverwritesExisting(): void
    {
        $first = $this->makeState(1, 10);
        $second = $this->makeState(2, 20);

        $this->store->upsert($this->id, $first);
        $this->store->upsert($this->id, $second);

        $loaded = $this->store->get($this->id);
        self::assertNotNull($loaded);
        self::assertSame(2, $loaded->version);
        self::assertEquals(20, $loaded->state->value);
    }

    #[Test]
    public function deleteRemovesState(): void
    {
        $envelope = $this->makeState(1, 42);

        $this->store->upsert($this->id, $envelope);
        $this->store->delete($this->id);

        self::assertNull($this->store->get($this->id));
    }

    #[Test]
    public function getReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->store->get($this->id));
    }

    #[Test]
    public function getReturnsNullForUnknownPersistenceId(): void
    {
        $unknownId = PersistenceId::of('counter', 'unknown');

        self::assertNull($this->store->get($unknownId));
    }

    #[Test]
    public function deleteOnNonExistentIdIsNoOp(): void
    {
        // Should not throw
        $this->store->delete($this->id);

        self::assertNull($this->store->get($this->id));
    }

    #[Test]
    public function stateIsSerializedAndDeserialized(): void
    {
        $state = new stdClass();
        $state->items = ['a', 'b', 'c'];
        $state->count = 3;

        $envelope = new DurableStateEnvelope(
            persistenceId: $this->id,
            version: 1,
            state: $state,
            stateType: stdClass::class,
            timestamp: new DateTimeImmutable('2026-01-15 10:00:00'),
            writerId: $this->testWriterId,
        );

        $this->store->upsert($this->id, $envelope);

        $loaded = $this->store->get($this->id);
        self::assertNotNull($loaded);
        self::assertEquals(['a', 'b', 'c'], $loaded->state->items);
        self::assertSame(3, $loaded->state->count);
    }

    #[Test]
    public function upsertStaleVersionThrowsConcurrentModification(): void
    {
        // Insert initial state (version=1 via Doctrine)
        $this->store->upsert($this->id, $this->makeState(1, 10));

        // Simulate a concurrent update by changing the version directly in the DB
        $this->em->getConnection()->executeStatement(
            "UPDATE nexus_durable_state SET version = 99 WHERE persistence_id = :pid",
            ['pid' => $this->id->toString()],
        );

        // Next upsert should fail — entity was loaded with version=1 but DB now has version=99
        $this->expectException(ConcurrentModificationException::class);

        $this->store->upsert($this->id, $this->makeState(2, 20));
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

        $this->store = new DoctrineDurableStateStore($this->em, PhpNativeSerializer::forTrustedData());
        $this->id = PersistenceId::of('counter', 'counter-1');
        $this->testWriterId = new Ulid();
    }

    private function makeState(int $version, int $value = 0): DurableStateEnvelope
    {
        $state = new stdClass();
        $state->value = $value;

        return new DurableStateEnvelope(
            persistenceId: $this->id,
            version: $version,
            state: $state,
            stateType: stdClass::class,
            timestamp: new DateTimeImmutable('2026-01-15 10:00:00'),
            writerId: $this->testWriterId,
        );
    }
}
