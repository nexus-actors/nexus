<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Support;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedEventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingEnvelopedEventBus::class)]
final class RecordingEnvelopedEventBusTest extends TestCase
{
    #[Test]
    public function recordsBothPlainAndEnvelopedPublications(): void
    {
        $nodeId = NodeId::generate();
        $bus = new RecordingEnvelopedEventBus();
        $event = new class () implements DomainEvent {};
        $envelopeEvent = new class () implements DomainEvent {};
        $envelope = new Envelope(
            $envelopeEvent,
            new MessageMetadata(
                id: MessageId::generate(),
                occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                causationId: Option::none(),
                correlationId: Option::none(),
                conversationId: Option::none(),
                schemaVersion: 1,
                traceParent: Option::none(),
                traceState: Option::none(),
                expiresAt: Option::none(),
                vectorClock: VectorClock::empty()->tick($nodeId),
            ),
        );

        $bus->publishEvent($event);
        $bus->publishEnveloped($envelope);

        self::assertSame([$event], $bus->recorded());
        self::assertSame([$envelope], $bus->recordedEnvelopes());
    }
}
