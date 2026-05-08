<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Metadata;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(MessageMetadata::class)]
final class MessageMetadataConstructorTest extends TestCase
{
    #[Test]
    public function rootProducesMetadataWithVectorClockTickedForProducingNode(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $clock = new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable {
return $this->now;
 }
        };
        $nodeId = NodeId::generate();

        $meta = MessageMetadata::root($clock, $nodeId);

        self::assertInstanceOf(MessageId::class, $meta->id);
        self::assertSame($now, $meta->occurredAt);
        self::assertTrue($meta->causationId->isNone());
        self::assertTrue($meta->correlationId->isNone());
        self::assertTrue($meta->conversationId->isNone());
        self::assertSame(1, $meta->schemaVersion);
        self::assertTrue($meta->traceParent->isNone());
        self::assertTrue($meta->traceState->isNone());
        self::assertTrue($meta->expiresAt->isNone());
        self::assertSame(1, $meta->vectorClock->counters[$nodeId->value()]);
    }

    #[Test]
    public function constructorAcceptsAllFieldsExplicitly(): void
    {
        $id = MessageId::generate();
        $cause = MessageId::generate();
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $expires = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        $vectorClock = VectorClock::empty()->tick(NodeId::generate());

        $meta = new MessageMetadata(
            id: $id,
            occurredAt: $now,
            causationId: Option::some($cause),
            correlationId: Option::none(),
            conversationId: Option::none(),
            schemaVersion: 3,
            traceParent: Option::some('00-abc-def-01'),
            traceState: Option::none(),
            expiresAt: Option::some($expires),
            vectorClock: $vectorClock,
        );

        self::assertSame($id, $meta->id);
        self::assertTrue($meta->causationId->isSome());
        self::assertSame(3, $meta->schemaVersion);
        self::assertSame($vectorClock, $meta->vectorClock);
    }
}
