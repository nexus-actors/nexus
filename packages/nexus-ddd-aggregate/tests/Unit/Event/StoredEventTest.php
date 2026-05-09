<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\StoredEvent;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoredEvent::class)]
final class StoredEventTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $event = new StoredEventTestEvent('sku-1');
        $occurredAt = new DateTimeImmutable('2026-05-08T12:00:00+00:00');
        $stored = new StoredEvent($streamId, 1, $event, StoredEventTestEvent::class, $occurredAt, ['key' => 'value']);

        self::assertSame($streamId, $stored->streamId);
        self::assertSame(1, $stored->sequenceNr);
        self::assertSame($event, $stored->event);
        self::assertSame(StoredEventTestEvent::class, $stored->eventType);
        self::assertSame($occurredAt, $stored->occurredAt);
        self::assertSame(['key' => 'value'], $stored->metadata);
    }

    #[Test]
    public function constructorDefaultsMetadataToEmptyArray(): void
    {
        $stored = new StoredEvent(
            new AggregateStreamId('App\\Order', 'order-1'),
            1,
            new StoredEventTestEvent('sku-1'),
            StoredEventTestEvent::class,
            new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        );

        self::assertSame([], $stored->metadata);
    }
}

final readonly class StoredEventTestEvent implements DomainEvent
{
    public function __construct(public string $sku) {}
}
