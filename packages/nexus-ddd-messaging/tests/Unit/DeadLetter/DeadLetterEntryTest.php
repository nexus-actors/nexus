<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\DeadLetter;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\DeadLetter\DeadLetterEntry;
use Monadial\Nexus\Ddd\Messaging\DeadLetter\DeadLetterReason;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeadLetterEntry::class)]
final class DeadLetterEntryTest extends TestCase
{
    #[Test]
    public function exposesAllFields(): void
    {
        $envelope = new Envelope(
            (object) [],
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
                vectorClock: Option::none(),
            ),
        );
        $cause = new RuntimeException('boom');
        $now = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $entry = new DeadLetterEntry($envelope, $cause, $now, 5, DeadLetterReason::TerminalFailure);

        self::assertSame($envelope, $entry->envelope);
        self::assertSame($cause, $entry->cause);
        self::assertSame($now, $entry->deadLetteredAt);
        self::assertSame(5, $entry->attemptsBeforeDeadLetter);
        self::assertSame(DeadLetterReason::TerminalFailure, $entry->reason);
    }
}
