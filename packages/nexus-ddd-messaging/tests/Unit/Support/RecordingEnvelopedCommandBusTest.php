<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Support;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedCommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingEnvelopedCommandBus::class)]
final class RecordingEnvelopedCommandBusTest extends TestCase
{
    #[Test]
    public function recordsBothPlainAndEnvelopedDispatches(): void
    {
        $nodeId = NodeId::generate();
        $bus = new RecordingEnvelopedCommandBus();
        $cmd = new class () implements Command {};
        $envelopeCmd = new class () implements Command {};
        $envelope = new Envelope(
            $envelopeCmd,
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

        $bus->dispatchCommand($cmd);
        $bus->dispatchEnveloped($envelope);

        self::assertSame([$cmd], $bus->recorded());
        self::assertSame([$envelope], $bus->recordedEnvelopes());
    }
}
