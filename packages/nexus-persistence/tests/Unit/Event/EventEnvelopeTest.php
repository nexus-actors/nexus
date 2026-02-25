<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\Event;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EventEnvelope::class)]
final class EventEnvelopeTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $persistenceId = PersistenceId::of('order', 'order-123');
        $event = new stdClass();
        $event->amount = 100;
        $timestamp = new DateTimeImmutable('2026-01-15T10:30:00+00:00');

        $envelope = new EventEnvelope(
            persistenceId: $persistenceId,
            sequenceNr: 1,
            event: $event,
            eventType: 'OrderPlaced',
            timestamp: $timestamp,
            writerUuid: 'test-writer',
        );

        self::assertSame($persistenceId, $envelope->persistenceId);
        self::assertSame(1, $envelope->sequenceNr);
        self::assertSame($event, $envelope->event);
        self::assertSame('OrderPlaced', $envelope->eventType);
        self::assertSame($timestamp, $envelope->timestamp);
    }

    #[Test]
    public function metadataDefaultsToEmptyArray(): void
    {
        $envelope = new EventEnvelope(
            persistenceId: PersistenceId::of('order', 'order-1'),
            sequenceNr: 1,
            event: new stdClass(),
            eventType: 'OrderPlaced',
            timestamp: new DateTimeImmutable(),
            writerUuid: 'test-writer',
        );

        self::assertSame([], $envelope->metadata);
    }

    #[Test]
    public function constructsWithMetadata(): void
    {
        $metadata = ['causationId' => 'def-456', 'correlationId' => 'abc-123'];

        $envelope = new EventEnvelope(
            persistenceId: PersistenceId::of('order', 'order-1'),
            sequenceNr: 1,
            event: new stdClass(),
            eventType: 'OrderPlaced',
            timestamp: new DateTimeImmutable(),
            writerUuid: 'test-writer',
            metadata: $metadata,
        );

        self::assertSame($metadata, $envelope->metadata);
    }

    #[Test]
    public function withMetadataReturnsNewInstance(): void
    {
        $original = new EventEnvelope(
            persistenceId: PersistenceId::of('order', 'order-1'),
            sequenceNr: 1,
            event: new stdClass(),
            eventType: 'OrderPlaced',
            timestamp: new DateTimeImmutable(),
            writerUuid: 'test-writer',
            metadata: ['correlationId' => 'abc-123'],
        );

        $updated = $original->withMetadata(['traceId' => 'trace-789']);

        self::assertNotSame($original, $updated);
        self::assertSame(['correlationId' => 'abc-123'], $original->metadata);
        self::assertSame(['correlationId' => 'abc-123', 'traceId' => 'trace-789'], $updated->metadata);
    }

    #[Test]
    public function withMetadataOverridesExistingKeys(): void
    {
        $original = new EventEnvelope(
            persistenceId: PersistenceId::of('order', 'order-1'),
            sequenceNr: 1,
            event: new stdClass(),
            eventType: 'OrderPlaced',
            timestamp: new DateTimeImmutable(),
            writerUuid: 'test-writer',
            metadata: ['key' => 'old-value'],
        );

        $updated = $original->withMetadata(['key' => 'new-value']);

        self::assertSame(['key' => 'old-value'], $original->metadata);
        self::assertSame(['key' => 'new-value'], $updated->metadata);
    }

    #[Test]
    public function withMetadataPreservesOtherProperties(): void
    {
        $persistenceId = PersistenceId::of('order', 'order-1');
        $event = new stdClass();
        $timestamp = new DateTimeImmutable();

        $original = new EventEnvelope(
            persistenceId: $persistenceId,
            sequenceNr: 5,
            event: $event,
            eventType: 'OrderPlaced',
            timestamp: $timestamp,
            writerUuid: 'test-writer',
        );

        $updated = $original->withMetadata(['key' => 'value']);

        self::assertSame($persistenceId, $updated->persistenceId);
        self::assertSame(5, $updated->sequenceNr);
        self::assertSame($event, $updated->event);
        self::assertSame('OrderPlaced', $updated->eventType);
        self::assertSame($timestamp, $updated->timestamp);
    }

    #[Test]
    public function constructs_with_writer_uuid(): void
    {
        $envelope = new EventEnvelope(
            persistenceId: PersistenceId::of('order', 'order-1'),
            sequenceNr: 1,
            event: new stdClass(),
            eventType: 'OrderPlaced',
            timestamp: new DateTimeImmutable(),
            writerUuid: 'abc-123-uuid',
        );

        self::assertSame('abc-123-uuid', $envelope->writerUuid);
    }
}
