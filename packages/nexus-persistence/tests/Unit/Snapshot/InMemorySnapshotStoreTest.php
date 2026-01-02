<?php
declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\Snapshot;

use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemorySnapshotStore::class)]
final class InMemorySnapshotStoreTest extends TestCase
{
    private InMemorySnapshotStore $store;
    private PersistenceId $id;

    protected function setUp(): void
    {
        $this->store = new InMemorySnapshotStore();
        $this->id = PersistenceId::of('order', 'order-1');
    }

    private function makeSnapshot(int $sequenceNr): SnapshotEnvelope
    {
        $state = new \stdClass();
        $state->total = $sequenceNr * 100;

        return new SnapshotEnvelope(
            persistenceId: $this->id,
            sequenceNr: $sequenceNr,
            state: $state,
            stateType: 'OrderState',
            timestamp: new \DateTimeImmutable(),
        );
    }

    #[Test]
    public function saveAndLoad(): void
    {
        $snapshot = $this->makeSnapshot(5);

        $this->store->save($this->id, $snapshot);

        $loaded = $this->store->load($this->id);
        self::assertSame($snapshot, $loaded);
    }

    #[Test]
    public function loadReturnsLatestSnapshot(): void
    {
        $snapshot1 = $this->makeSnapshot(5);
        $snapshot2 = $this->makeSnapshot(10);
        $snapshot3 = $this->makeSnapshot(15);

        $this->store->save($this->id, $snapshot1);
        $this->store->save($this->id, $snapshot2);
        $this->store->save($this->id, $snapshot3);

        $loaded = $this->store->load($this->id);
        self::assertSame($snapshot3, $loaded);
    }

    #[Test]
    public function deleteRemovesSnapshotsUpToSequenceNr(): void
    {
        $snapshot1 = $this->makeSnapshot(5);
        $snapshot2 = $this->makeSnapshot(10);
        $snapshot3 = $this->makeSnapshot(15);

        $this->store->save($this->id, $snapshot1);
        $this->store->save($this->id, $snapshot2);
        $this->store->save($this->id, $snapshot3);

        $this->store->delete($this->id, 10);

        $loaded = $this->store->load($this->id);
        self::assertNotNull($loaded);
        self::assertSame(15, $loaded->sequenceNr);
    }

    #[Test]
    public function deleteAllSnapshotsReturnsNull(): void
    {
        $snapshot1 = $this->makeSnapshot(5);
        $snapshot2 = $this->makeSnapshot(10);

        $this->store->save($this->id, $snapshot1);
        $this->store->save($this->id, $snapshot2);

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
}
