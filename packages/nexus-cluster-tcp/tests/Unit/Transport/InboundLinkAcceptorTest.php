<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Transport;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Cluster\Tcp\Protocol\FrameType;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\FakePeerLink;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingRef;
use Monadial\Nexus\Cluster\Tcp\Transport\InboundLinkAcceptor;
use Monadial\Nexus\Cluster\Tcp\Transport\LinkClosedNotice;
use Monadial\Nexus\Cluster\Tcp\Transport\LinkFrame;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Unit tests for the pump: capacity gate, spawner hand-off, C3 stamping, the pre-auth
 * flood-bound close on a `Dropped` enqueue, and close bookkeeping. The Slowloris deadline and
 * the per-link frame state machine both moved to {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor}
 * — see the class docblock for exactly what stayed here.
 */
#[CoversClass(InboundLinkAcceptor::class)]
final class InboundLinkAcceptorTest extends TestCase
{
    private ClockInterface $clock;

    #[Test]
    public function capacityGateClosesTheLinkBeyondTheCapWithoutSpawning(): void
    {
        $spawnCalls = 0;

        $acceptor = new InboundLinkAcceptor(
            1,
            static function () use (&$spawnCalls): RecordingRef {
                ++$spawnCalls;

                return new RecordingRef();
            },
            $this->clock,
        );

        $first = new FakePeerLink();
        $second = new FakePeerLink();

        $acceptor->accept($first);
        $acceptor->accept($second);

        self::assertSame(1, $acceptor->liveInboundCount(), 'only the first link counts as live');
        self::assertSame(1, $spawnCalls, 'the second link must never reach the spawner');
        self::assertFalse($first->wasClosed(), 'the first link must stay open');
        self::assertTrue($second->wasClosed(), 'the second link must be rejected at capacity');
    }

    #[Test]
    public function everyFrameIsStampedAndOfferedToTheSpawnedActor(): void
    {
        $ref = new RecordingRef();
        $acceptor = new InboundLinkAcceptor(10, static fn(): RecordingRef => $ref, $this->clock);

        $link = new FakePeerLink();
        $acceptor->accept($link);

        $frame = new Frame(FrameType::Handshake, 'payload');
        $link->receiveFrame($frame);

        self::assertCount(1, $ref->offered);
        self::assertInstanceOf(LinkFrame::class, $ref->offered[0]);
        self::assertSame($frame, $ref->offered[0]->frame);
        self::assertEquals($this->clock->now(), $ref->offered[0]->observedAt);
    }

    #[Test]
    public function aDroppedEnqueueClosesTheLink(): void
    {
        $ref = new RecordingRef();
        $ref->offerResult = EnqueueResult::Dropped;
        $acceptor = new InboundLinkAcceptor(10, static fn(): RecordingRef => $ref, $this->clock);

        $link = new FakePeerLink();
        $acceptor->accept($link);

        $link->receiveFrame(new Frame(FrameType::Gossip, ''));

        self::assertTrue($link->wasClosed(), 'a mailbox-Dropped frame is the pre-auth flood bound');
    }

    #[Test]
    public function aBackpressuredEnqueueDoesNotCloseTheLink(): void
    {
        $ref = new RecordingRef();
        $ref->offerResult = EnqueueResult::Backpressured;
        $acceptor = new InboundLinkAcceptor(10, static fn(): RecordingRef => $ref, $this->clock);

        $link = new FakePeerLink();
        $acceptor->accept($link);

        $link->receiveFrame(new Frame(FrameType::Gossip, ''));

        self::assertFalse($link->wasClosed());
    }

    #[Test]
    public function aRefWithoutBackpressureSupportFallsBackToTell(): void
    {
        $ref = new class implements ActorRef {
            /** @var list<object> */
            public array $told = [];

            public function tell(object $message): void
            {
                $this->told[] = $message;
            }

            public function ask(object $message, Duration $timeout): Future
            {
                throw new RuntimeException('not implemented');
            }

            public function path(): ActorPath
            {
                return ActorPath::root()->child('user')->child('plain-ref');
            }

            public function isAlive(): bool
            {
                return true;
            }
        };

        $acceptor = new InboundLinkAcceptor(10, static fn(): object => $ref, $this->clock);
        $link = new FakePeerLink();
        $acceptor->accept($link);

        $frame = new Frame(FrameType::Gossip, '');
        $link->receiveFrame($frame);

        self::assertCount(1, $ref->told);
        self::assertInstanceOf(LinkFrame::class, $ref->told[0]);
        self::assertSame($frame, $ref->told[0]->frame);
        self::assertFalse($link->wasClosed());
    }

    #[Test]
    public function closeTellsLinkClosedNoticeAndRemovesFromLiveCount(): void
    {
        $ref = new RecordingRef();
        $acceptor = new InboundLinkAcceptor(10, static fn(): RecordingRef => $ref, $this->clock);

        $link = new FakePeerLink();
        $acceptor->accept($link);
        self::assertSame(1, $acceptor->liveInboundCount());

        $link->triggerClose();

        self::assertCount(1, $ref->told);
        self::assertInstanceOf(LinkClosedNotice::class, $ref->told[0]);
        self::assertSame(0, $acceptor->liveInboundCount(), 'the closed link must be removed from the live count');

        // FakePeerLink::triggerClose() is itself idempotent, mirroring a real link's close semantics.
        $link->triggerClose();
        self::assertCount(1, $ref->told, 'a second close notification must not re-tell LinkClosedNotice');
    }

    #[Test]
    public function theSpawnerReceivesTheAcceptedLink(): void
    {
        /** @var list<PeerLink> $spawnedFor */
        $spawnedFor = [];

        $acceptor = new InboundLinkAcceptor(
            10,
            static function (PeerLink $link) use (&$spawnedFor): RecordingRef {
                $spawnedFor[] = $link;

                return new RecordingRef();
            },
            $this->clock,
        );

        $link = new FakePeerLink();
        $acceptor->accept($link);

        self::assertSame([$link], $spawnedFor);
    }

    protected function setUp(): void
    {
        $this->clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }
}
