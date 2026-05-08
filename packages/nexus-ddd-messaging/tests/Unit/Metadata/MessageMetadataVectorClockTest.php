<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Metadata;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClockOrdering;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageMetadata::class)]
final class MessageMetadataVectorClockTest extends TestCase
{
    private DateTimeImmutable $now;

    #[Test]
    public function happensBeforeReturnsTrueWhenOrdered(): void
    {
        $nodeId = NodeId::generate();
        $earlier = $this->metaWithClock(VectorClock::empty()->tick($nodeId));
        $later = $this->metaWithClock(VectorClock::empty()->tick($nodeId)->tick($nodeId));

        self::assertTrue($earlier->happensBefore($later));
        self::assertFalse($later->happensBefore($earlier));
    }

    #[Test]
    public function happensAfterReturnsTrueWhenOrdered(): void
    {
        $nodeId = NodeId::generate();
        $earlier = $this->metaWithClock(VectorClock::empty()->tick($nodeId));
        $later = $this->metaWithClock(VectorClock::empty()->tick($nodeId)->tick($nodeId));

        self::assertTrue($later->happensAfter($earlier));
        self::assertFalse($earlier->happensAfter($later));
    }

    #[Test]
    public function isConcurrentWithReturnsTrueForConcurrentClocks(): void
    {
        $nodeA = NodeId::generate();
        $nodeB = NodeId::generate();
        $metaA = $this->metaWithClock(VectorClock::empty()->tick($nodeA));
        $metaB = $this->metaWithClock(VectorClock::empty()->tick($nodeB));

        self::assertTrue($metaA->isConcurrentWith($metaB));
    }

    #[Test]
    public function compareCausalityWithReturnsHappensBefore(): void
    {
        $nodeId = NodeId::generate();
        $earlier = $this->metaWithClock(VectorClock::empty()->tick($nodeId));
        $later = $this->metaWithClock(VectorClock::empty()->tick($nodeId)->tick($nodeId));

        self::assertSame(VectorClockOrdering::HappensBefore, $earlier->compareCausalityWith($later));
        self::assertSame(VectorClockOrdering::HappensAfter, $later->compareCausalityWith($earlier));
    }

    #[Test]
    public function compareCausalityWithReturnsEqualForSameClock(): void
    {
        $vc = VectorClock::empty()->tick(NodeId::generate());
        $a = $this->metaWithClock($vc);
        $b = $this->metaWithClock($vc);

        self::assertSame(VectorClockOrdering::Equal, $a->compareCausalityWith($b));
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
    }

    private function metaWithClock(VectorClock $vc): MessageMetadata
    {
        return new MessageMetadata(
            id: MessageId::generate(),
            occurredAt: $this->now,
            causationId: Option::none(),
            correlationId: Option::none(),
            conversationId: Option::none(),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
            vectorClock: $vc,
        );
    }
}
