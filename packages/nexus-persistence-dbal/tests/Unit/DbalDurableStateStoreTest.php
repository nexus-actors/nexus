<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal\Tests\Unit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Persistence\Dbal\DbalDurableStateStore;
use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;
use Monadial\Nexus\Persistence\Exception\ConcurrentModificationException;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(DbalDurableStateStore::class)]
final class DbalDurableStateStoreTest extends TestCase
{
    private Connection $connection;
    private DbalDurableStateStore $store;
    private PersistenceId $id;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new PersistenceSchemaManager($this->connection))->createSchema();
        $this->store = new DbalDurableStateStore($this->connection);
        $this->id = PersistenceId::of('counter', 'counter-1');
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
        );
    }

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
        );

        $this->store->upsert($this->id, $envelope);

        $loaded = $this->store->get($this->id);
        self::assertNotNull($loaded);
        self::assertEquals(['a', 'b', 'c'], $loaded->state->items);
        self::assertSame(3, $loaded->state->count);
    }

    #[Test]
    public function upsertWithStaleVersionThrowsConcurrentModification(): void
    {
        // Insert version 1
        $this->store->upsert($this->id, $this->makeState(1, 10));

        // Upsert version 2 (expected previous version = 1) — should succeed
        $this->store->upsert($this->id, $this->makeState(2, 20));

        // Upsert version 2 again (expected previous version = 1) — should fail
        // because the DB version is already 2
        $this->expectException(ConcurrentModificationException::class);

        $this->store->upsert($this->id, $this->makeState(2, 30));
    }
}
