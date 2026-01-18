<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Persistence\Dbal\DbalDurableStateStore;
use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

    private function makeState(int $revision, int $value = 0): DurableStateEnvelope
    {
        $state = new \stdClass();
        $state->value = $value;

        return new DurableStateEnvelope(
            persistenceId: $this->id,
            revision: $revision,
            state: $state,
            stateType: 'CounterState',
            timestamp: new \DateTimeImmutable('2026-01-15 10:00:00'),
        );
    }

    #[Test]
    public function upsertAndGet(): void
    {
        $envelope = $this->makeState(1, 42);

        $this->store->upsert($this->id, $envelope);

        $loaded = $this->store->get($this->id);
        self::assertNotNull($loaded);
        self::assertSame(1, $loaded->revision);
        self::assertSame('CounterState', $loaded->stateType);
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
        self::assertSame(2, $loaded->revision);
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
        $state = new \stdClass();
        $state->items = ['a', 'b', 'c'];
        $state->count = 3;

        $envelope = new DurableStateEnvelope(
            persistenceId: $this->id,
            revision: 1,
            state: $state,
            stateType: 'CounterState',
            timestamp: new \DateTimeImmutable('2026-01-15 10:00:00'),
        );

        $this->store->upsert($this->id, $envelope);

        $loaded = $this->store->get($this->id);
        self::assertNotNull($loaded);
        self::assertEquals(['a', 'b', 'c'], $loaded->state->items);
        self::assertSame(3, $loaded->state->count);
    }
}
