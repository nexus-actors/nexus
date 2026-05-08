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
            vectorClock: Option::some($vc),
        );
    }

    private function metaWithoutClock(): MessageMetadata
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
            vectorClock: Option::none(),
        );
    }

    #[Test]
    public function hasVectorClockReturnsFalseWhenAbsent(): void
    {
        self::assertFalse($this->metaWithoutClock()->hasVectorClock());
    }

    #[Test]
    public function hasVectorClockReturnsTrueWhenPresent(): void
    {
        self::assertTrue($this->metaWithClock(VectorClock::empty())->hasVectorClock());
    }

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
    public function compareCausalityWithReturnsNoneWhenEitherLacksClock(): void
    {
        $withClock = $this->metaWithClock(VectorClock::empty());
        $withoutClock = $this->metaWithoutClock();

        self::assertTrue($withClock->compareCausalityWith($withoutClock)->isNone());
        self::assertTrue($withoutClock->compareCausalityWith($withClock)->isNone());
    }

    #[Test]
    public function compareCausalityWithReturnsOrderingWhenBothHaveClocks(): void
    {
        $nodeId = NodeId::generate();
        $earlier = $this->metaWithClock(VectorClock::empty()->tick($nodeId));
        $later = $this->metaWithClock(VectorClock::empty()->tick($nodeId)->tick($nodeId));

        $result = $earlier->compareCausalityWith($later);
        self::assertTrue($result->isSome());
        self::assertSame(VectorClockOrdering::HappensBefore, $result->get());
    }
}
