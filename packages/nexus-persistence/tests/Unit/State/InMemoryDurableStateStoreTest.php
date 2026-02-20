<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\State;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(InMemoryDurableStateStore::class)]
final class InMemoryDurableStateStoreTest extends TestCase
{
    private InMemoryDurableStateStore $store;
    private PersistenceId $id;

    #[Test]
    public function upsertAndGet(): void
    {
        $envelope = $this->makeState(1, 42);

        $this->store->upsert($this->id, $envelope);

        $loaded = $this->store->get($this->id);
        self::assertSame($envelope, $loaded);
    }

    #[Test]
    public function upsertOverwritesExisting(): void
    {
        $first = $this->makeState(1, 10);
        $second = $this->makeState(2, 20);

        $this->store->upsert($this->id, $first);
        $this->store->upsert($this->id, $second);

        $loaded = $this->store->get($this->id);
        self::assertSame($second, $loaded);
        self::assertSame(20, $loaded->state->value);
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

    protected function setUp(): void
    {
        $this->store = new InMemoryDurableStateStore();
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
            stateType: 'CounterState',
            timestamp: new DateTimeImmutable(),
        );
    }
}
