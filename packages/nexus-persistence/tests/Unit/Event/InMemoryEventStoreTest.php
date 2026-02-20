<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\Event;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(InMemoryEventStore::class)]
final class InMemoryEventStoreTest extends TestCase
{
    private InMemoryEventStore $store;
    private PersistenceId $id;

    #[Test]
    public function persistsSingleEvent(): void
    {
        $envelope = $this->makeEnvelope(1);

        $this->store->persist($this->id, $envelope);

        $loaded = iterator_to_array($this->store->load($this->id));
        self::assertCount(1, $loaded);
        self::assertSame($envelope, $loaded[0]);
    }

    #[Test]
    public function persistsMultipleEventsAtomically(): void
    {
        $e1 = $this->makeEnvelope(1, 'OrderPlaced');
        $e2 = $this->makeEnvelope(2, 'OrderConfirmed');
        $e3 = $this->makeEnvelope(3, 'OrderShipped');

        $this->store->persist($this->id, $e1, $e2, $e3);

        $loaded = iterator_to_array($this->store->load($this->id));
        self::assertCount(3, $loaded);
        self::assertSame($e1, $loaded[0]);
        self::assertSame($e2, $loaded[1]);
        self::assertSame($e3, $loaded[2]);
    }

    #[Test]
    public function loadsWithSequenceNrRange(): void
    {
        $e1 = $this->makeEnvelope(1);
        $e2 = $this->makeEnvelope(2);
        $e3 = $this->makeEnvelope(3);
        $e4 = $this->makeEnvelope(4);
        $e5 = $this->makeEnvelope(5);

        $this->store->persist($this->id, $e1, $e2, $e3, $e4, $e5);

        $loaded = iterator_to_array($this->store->load($this->id, fromSequenceNr: 2, toSequenceNr: 4));
        self::assertCount(3, $loaded);
        self::assertSame($e2, $loaded[0]);
        self::assertSame($e3, $loaded[1]);
        self::assertSame($e4, $loaded[2]);
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
        self::assertSame($e1, $loaded[0]);
        self::assertSame($e2, $loaded[1]);
    }

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $this->id = PersistenceId::of('order', 'order-1');
    }

    private function makeEnvelope(int $sequenceNr, string $eventType = 'OrderPlaced'): EventEnvelope
    {
        return new EventEnvelope(
            persistenceId: $this->id,
            sequenceNr: $sequenceNr,
            event: new stdClass(),
            eventType: $eventType,
            timestamp: new DateTimeImmutable(),
        );
    }
}
