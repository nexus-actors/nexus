<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal\Tests\Unit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Persistence\Dbal\DbalEventStore;
use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Exception\ConcurrentModificationException;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(DbalEventStore::class)]
final class DbalEventStoreTest extends TestCase
{
    private Connection $connection;
    private DbalEventStore $store;
    private PersistenceId $id;

    #[Test]
    public function persistsSingleEvent(): void
    {
        $envelope = $this->makeEnvelope(1);

        $this->store->persist($this->id, $envelope);

        $loaded = iterator_to_array($this->store->load($this->id));
        self::assertCount(1, $loaded);
        self::assertSame(1, $loaded[0]->sequenceNr);
        self::assertSame(stdClass::class, $loaded[0]->eventType);
    }

    #[Test]
    public function persistsMultipleEventsAtomically(): void
    {
        $e1 = $this->makeEnvelope(1);
        $e2 = $this->makeEnvelope(2);
        $e3 = $this->makeEnvelope(3);

        $this->store->persist($this->id, $e1, $e2, $e3);

        $loaded = iterator_to_array($this->store->load($this->id));
        self::assertCount(3, $loaded);
        self::assertSame(1, $loaded[0]->sequenceNr);
        self::assertSame(stdClass::class, $loaded[0]->eventType);
        self::assertSame(2, $loaded[1]->sequenceNr);
        self::assertSame(stdClass::class, $loaded[1]->eventType);
        self::assertSame(3, $loaded[2]->sequenceNr);
        self::assertSame(stdClass::class, $loaded[2]->eventType);
    }

    #[Test]
    public function loadsWithSequenceNrRange(): void
    {
        $this->store->persist(
            $this->id,
            $this->makeEnvelope(1),
            $this->makeEnvelope(2),
            $this->makeEnvelope(3),
            $this->makeEnvelope(4),
            $this->makeEnvelope(5),
        );

        $loaded = iterator_to_array($this->store->load($this->id, fromSequenceNr: 2, toSequenceNr: 4));
        self::assertCount(3, $loaded);
        self::assertSame(2, $loaded[0]->sequenceNr);
        self::assertSame(3, $loaded[1]->sequenceNr);
        self::assertSame(4, $loaded[2]->sequenceNr);
    }

    #[Test]
    public function highestSequenceNrReturnsZeroWhenEmpty(): void
    {
        self::assertSame(0, $this->store->highestSequenceNr($this->id));
    }

    #[Test]
    public function highestSequenceNrReturnsMaxAfterPersist(): void
    {
        $this->store->persist(
            $this->id,
            $this->makeEnvelope(1),
            $this->makeEnvelope(2),
            $this->makeEnvelope(3),
        );

        self::assertSame(3, $this->store->highestSequenceNr($this->id));
    }

    #[Test]
    public function deleteUpToRemovesEventsUpToSequenceNr(): void
    {
        $this->store->persist(
            $this->id,
            $this->makeEnvelope(1),
            $this->makeEnvelope(2),
            $this->makeEnvelope(3),
            $this->makeEnvelope(4),
        );

        $this->store->deleteUpTo($this->id, 2);

        $loaded = iterator_to_array($this->store->load($this->id));
        self::assertCount(2, $loaded);
        self::assertSame(3, $loaded[0]->sequenceNr);
        self::assertSame(4, $loaded[1]->sequenceNr);
    }

    #[Test]
    public function loadReturnsEmptyForUnknownPersistenceId(): void
    {
        $unknownId = PersistenceId::of('order', 'unknown');

        $loaded = iterator_to_array($this->store->load($unknownId));
        self::assertSame([], $loaded);
    }

    #[Test]
    public function persistAppendsAcrossMultipleCalls(): void
    {
        $e1 = $this->makeEnvelope(1);
        $e2 = $this->makeEnvelope(2);

        $this->store->persist($this->id, $e1);
        $this->store->persist($this->id, $e2);

        $loaded = iterator_to_array($this->store->load($this->id));
        self::assertCount(2, $loaded);
        self::assertSame(1, $loaded[0]->sequenceNr);
        self::assertSame(2, $loaded[1]->sequenceNr);
    }

    #[Test]
    public function persistsAndLoadsMetadata(): void
    {
        $envelope = new EventEnvelope(
            persistenceId: $this->id,
            sequenceNr: 1,
            event: new stdClass(),
            eventType: stdClass::class,
            timestamp: new DateTimeImmutable('2026-01-15 10:00:00'),
            metadata: ['source' => 'api', 'user_id' => '123'],
        );

        $this->store->persist($this->id, $envelope);

        $loaded = iterator_to_array($this->store->load($this->id));
        self::assertSame(['source' => 'api', 'user_id' => '123'], $loaded[0]->metadata);
    }

    #[Test]
    public function emptyMetadataIsLoadedAsEmptyArray(): void
    {
        $envelope = $this->makeEnvelope(1);

        $this->store->persist($this->id, $envelope);

        $loaded = iterator_to_array($this->store->load($this->id));
        self::assertSame([], $loaded[0]->metadata);
    }

    #[Test]
    public function eventIsSerializedAndDeserialized(): void
    {
        $event = new stdClass();
        $event->orderId = 'order-42';
        $event->amount = 99.95;

        $envelope = new EventEnvelope(
            persistenceId: $this->id,
            sequenceNr: 1,
            event: $event,
            eventType: stdClass::class,
            timestamp: new DateTimeImmutable('2026-01-15 10:00:00'),
        );

        $this->store->persist($this->id, $envelope);

        $loaded = iterator_to_array($this->store->load($this->id));
        self::assertEquals('order-42', $loaded[0]->event->orderId);
        self::assertEquals(99.95, $loaded[0]->event->amount);
    }

    #[Test]
    public function persistDuplicateSequenceThrowsConcurrentModification(): void
    {
        $this->store->persist($this->id, $this->makeEnvelope(1));

        $this->expectException(ConcurrentModificationException::class);

        $this->store->persist($this->id, $this->makeEnvelope(1));
    }

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new PersistenceSchemaManager($this->connection))->createSchema();
        $this->store = new DbalEventStore($this->connection);
        $this->id = PersistenceId::of('order', 'order-1');
    }

    private function makeEnvelope(int $sequenceNr, string $eventType = stdClass::class): EventEnvelope
    {
        return new EventEnvelope(
            persistenceId: $this->id,
            sequenceNr: $sequenceNr,
            event: new stdClass(),
            eventType: $eventType,
            timestamp: new DateTimeImmutable('2026-01-15 10:00:00'),
        );
    }
}
