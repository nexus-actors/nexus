<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Transport;

use Closure;
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
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
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
 * flood-bound close on a `Dropped` enqueue, close bookkeeping, and the OUT-OF-BAND Slowloris
 * backstop (armed per accepted link, disarmed via the `$onIdentified` seam or on link close).
 * The per-link frame state machine and the in-band
 * {@see \Monadial\Nexus\Cluster\Tcp\Transport\HandshakeDeadline} both live on
 * {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor} — see the acceptor's class
 * docblock for exactly what stays here and why the backstop exists (mailbox starvation).
 */
#[CoversClass(InboundLinkAcceptor::class)]
final class InboundLinkAcceptorTest extends TestCase
{
    private const int HANDSHAKE_TIMEOUT_SECONDS = 10;

    /** Mirrors the acceptor's private BACKSTOP_GRACE_SECONDS — the backstop fires at timeout + grace. */
    private const int BACKSTOP_GRACE_SECONDS = 1;

    private TestRuntime $runtime;

    private ClockInterface $clock;

    #[Test]
    public function capacityGateClosesTheLinkBeyondTheCapWithoutSpawning(): void
    {
        $spawnCalls = 0;

        $acceptor = $this->acceptor(
            spawner: static function () use (&$spawnCalls): RecordingRef {
                ++$spawnCalls;

                return new RecordingRef();
            },
            maxInboundLinks: 1,
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
        $acceptor = $this->acceptor(spawner: static fn(): RecordingRef => $ref);

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
        $acceptor = $this->acceptor(spawner: static fn(): RecordingRef => $ref);

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
        $acceptor = $this->acceptor(spawner: static fn(): RecordingRef => $ref);

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

        $acceptor = $this->acceptor(spawner: static fn(): object => $ref);
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
        $acceptor = $this->acceptor(spawner: static fn(): RecordingRef => $ref);

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
    public function theSpawnerReceivesTheAcceptedLinkAndTheOnIdentifiedSeam(): void
    {
        /** @var list<PeerLink> $spawnedFor */
        $spawnedFor = [];
        /** @var list<Closure> $seams */
        $seams = [];

        $acceptor = $this->acceptor(
            spawner: static function (PeerLink $link, Closure $onIdentified) use (&$spawnedFor, &$seams): RecordingRef {
                $spawnedFor[] = $link;
                $seams[] = $onIdentified;

                return new RecordingRef();
            },
        );

        $link = new FakePeerLink();
        $acceptor->accept($link);

        self::assertSame([$link], $spawnedFor);
        self::assertCount(1, $seams, 'every accepted link gets its own onIdentified seam');
    }

    // -------------------------------------------------------------------------
    // Out-of-band Slowloris backstop
    // -------------------------------------------------------------------------

    #[Test]
    public function theBackstopClosesAnUnidentifiedLinkOutOfBand(): void
    {
        // RecordingRef never identifies — standing in for a starved actor whose in-band
        // HandshakeDeadline was dropped by a filled-to-capacity mailbox.
        $acceptor = $this->acceptor(spawner: static fn(): RecordingRef => new RecordingRef());

        $link = new FakePeerLink();
        $acceptor->accept($link);

        $this->runtime->advanceTime(Duration::seconds(self::HANDSHAKE_TIMEOUT_SECONDS));
        self::assertFalse($link->wasClosed(), 'the backstop waits out the grace beyond the handshake timeout');

        $this->runtime->advanceTime(Duration::seconds(self::BACKSTOP_GRACE_SECONDS));
        self::assertTrue($link->wasClosed(), 'the backstop must close the raw link out-of-band');
    }

    #[Test]
    public function theOnIdentifiedSeamDisarmsTheBackstop(): void
    {
        /** @var ?Closure $seam */
        $seam = null;

        $acceptor = $this->acceptor(
            spawner: static function (PeerLink $_link, Closure $onIdentified) use (&$seam): RecordingRef {
                $seam = $onIdentified;

                return new RecordingRef();
            },
        );

        $link = new FakePeerLink();
        $acceptor->accept($link);

        self::assertNotNull($seam);
        $seam();

        $this->runtime->advanceTime(
            Duration::seconds(self::HANDSHAKE_TIMEOUT_SECONDS + self::BACKSTOP_GRACE_SECONDS + 60),
        );

        self::assertFalse($link->wasClosed(), 'an identified link must never be closed by the backstop');
    }

    #[Test]
    public function linkCloseDisarmsTheBackstop(): void
    {
        $acceptor = $this->acceptor(spawner: static fn(): RecordingRef => new RecordingRef());

        $link = new FakePeerLink();
        $acceptor->accept($link);

        // The remote disconnects before ever identifying: the backstop timer is cancelled with the
        // link, so it never invokes close() on our side (wasClosed() only records OUR close calls).
        $link->triggerClose();

        $this->runtime->advanceTime(
            Duration::seconds(self::HANDSHAKE_TIMEOUT_SECONDS + self::BACKSTOP_GRACE_SECONDS + 60),
        );

        self::assertFalse($link->wasClosed(), 'a closed link needs no backstop — the timer must be cancelled');
    }

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }

    private function acceptor(Closure $spawner, int $maxInboundLinks = 10): InboundLinkAcceptor
    {
        return new InboundLinkAcceptor(
            $this->runtime,
            $maxInboundLinks,
            Duration::seconds(self::HANDSHAKE_TIMEOUT_SECONDS),
            $spawner,
            $this->clock,
        );
    }
}
