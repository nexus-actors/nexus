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
    public function hasVectorClockReturnsFalseWhenAbsent(): void
    {
        self::assertFalse($this->metaWithoutClock()->hasVectorClock());
    }

    #[Test]
    public function hasVectorClockReturnsTrueWhenPresent(): void
    {
        $nodeId = NodeId::generate();
        self::assertTrue($this->metaWithClock(VectorClock::empty()->tick($nodeId))->hasVectorClock());
    }

    #[Test]
    public function happensBeforeReturnsFalseWhenEitherSideLacksClock(): void
    {
        $nodeId = NodeId::generate();
        $withClock = $this->metaWithClock(VectorClock::empty()->tick($nodeId));
        $withoutClock = $this->metaWithoutClock();

        self::assertFalse($withClock->happensBefore($withoutClock));
        self::assertFalse($withoutClock->happensBefore($withClock));
        self::assertFalse($withoutClock->happensBefore($withoutClock));
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
    public function happensAfterReturnsFalseWhenEitherSideLacksClock(): void
    {
        $nodeId = NodeId::generate();
        $withClock = $this->metaWithClock(VectorClock::empty()->tick($nodeId));
        $withoutClock = $this->metaWithoutClock();

        self::assertFalse($withClock->happensAfter($withoutClock));
        self::assertFalse($withoutClock->happensAfter($withClock));
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
    public function isConcurrentWithReturnsFalseWhenEitherSideLacksClock(): void
    {
        $withoutClock = $this->metaWithoutClock();
        self::assertFalse($withoutClock->isConcurrentWith($withoutClock));
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
    public function compareCausalityWithReturnsNoneWhenEitherSideLacksClock(): void
    {
        $nodeId = NodeId::generate();
        $withClock = $this->metaWithClock(VectorClock::empty()->tick($nodeId));
        $withoutClock = $this->metaWithoutClock();

        self::assertTrue($withClock->compareCausalityWith($withoutClock)->isNone());
        self::assertTrue($withoutClock->compareCausalityWith($withClock)->isNone());
        self::assertTrue($withoutClock->compareCausalityWith($withoutClock)->isNone());
    }

    #[Test]
    public function compareCausalityWithReturnsHappensBefore(): void
    {
        $nodeId = NodeId::generate();
        $earlier = $this->metaWithClock(VectorClock::empty()->tick($nodeId));
        $later = $this->metaWithClock(VectorClock::empty()->tick($nodeId)->tick($nodeId));

        self::assertSame(
            VectorClockOrdering::HappensBefore,
            $earlier->compareCausalityWith($later)->get(),
        );
        self::assertSame(
            VectorClockOrdering::HappensAfter,
            $later->compareCausalityWith($earlier)->get(),
        );
    }

    #[Test]
    public function compareCausalityWithReturnsEqualForSameClock(): void
    {
        $vc = VectorClock::empty()->tick(NodeId::generate());
        $a = $this->metaWithClock($vc);
        $b = $this->metaWithClock($vc);

        self::assertSame(VectorClockOrdering::Equal, $a->compareCausalityWith($b)->get());
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
}
