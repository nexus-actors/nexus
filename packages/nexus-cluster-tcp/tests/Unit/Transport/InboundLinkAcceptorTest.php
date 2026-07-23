<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Transport;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Cluster\Tcp\Protocol\FrameType;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\FakePeerLink;
use Monadial\Nexus\Cluster\Tcp\Transport\InboundLinkAcceptor;
use Monadial\Nexus\Cluster\Tcp\Transport\LinkState;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pump-extraction target: capacity gate, Slowloris deadline ownership,
 * frame forwarding, and close bookkeeping — moved verbatim out of
 * `ClusterNode::wireInboundLink()`. See the class docblock for exactly which half of the
 * original inline closures moved where.
 */
#[CoversClass(InboundLinkAcceptor::class)]
final class InboundLinkAcceptorTest extends TestCase
{
    private TestRuntime $runtime;

    #[Test]
    public function capacityGateClosesTheLinkBeyondTheCapWithoutInvokingFrameSink(): void
    {
        $frameSinkCalls = 0;

        $acceptor = new InboundLinkAcceptor(
            $this->runtime,
            1,
            Duration::seconds(10),
            static function () use (&$frameSinkCalls): void {
                ++$frameSinkCalls;
            },
            static function (): void {},
        );

        $first = new FakePeerLink();
        $second = new FakePeerLink();

        $acceptor->accept($first);
        $acceptor->accept($second);

        self::assertSame(1, $acceptor->liveInboundCount(), 'only the first link counts as live');
        self::assertFalse($first->wasClosed(), 'the first link must stay open');
        self::assertTrue($second->wasClosed(), 'the second link must be rejected at capacity');

        // The rejected link was never wired to the acceptor's onFrame handler, so a frame
        // delivered to it must not reach frameSink.
        $second->receiveFrame(new Frame(FrameType::Ping, ''));
        self::assertSame(0, $frameSinkCalls, 'a rejected link must never reach frameSink');
    }

    #[Test]
    public function slowlorisDeadlineClosesAnUnidentifiedLinkAndIsANoOpOnceIdentified(): void
    {
        $acceptor = new InboundLinkAcceptor(
            $this->runtime,
            10,
            Duration::seconds(5),
            static function (Frame $frame, LinkState $state): void {
                if ($frame->type === FrameType::Handshake) {
                    $state->peerAddr = new NodeAddress('test', 'dc', 'app', 'peer');
                }
            },
            static function (): void {},
        );

        $unidentified = new FakePeerLink();
        $identified = new FakePeerLink();

        $acceptor->accept($unidentified);
        $acceptor->accept($identified);
        self::assertSame(2, $acceptor->liveInboundCount());

        // Identify the second link (valid Handshake) before the deadline elapses.
        $identified->receiveFrame(new Frame(FrameType::Handshake, ''));

        $this->runtime->advanceTime(Duration::seconds(5));

        self::assertTrue(
            $unidentified->wasClosed(),
            'a link that never identifies must be closed once the handshake deadline elapses',
        );
        self::assertFalse(
            $identified->wasClosed(),
            'a link that already identified must be untouched — its deadline callback is a no-op',
        );
        self::assertSame(
            1,
            $acceptor->liveInboundCount(),
            'only the timed-out link is removed from the live count',
        );
    }

    #[Test]
    public function framesReachFrameSinkWithTheAcceptedLinksState(): void
    {
        /** @var list<array{Frame, LinkState, string}> $received */
        $received = [];

        $acceptor = new InboundLinkAcceptor(
            $this->runtime,
            10,
            Duration::seconds(10),
            static function (Frame $frame, LinkState $state, string $remoteLabel) use (&$received): void {
                $received[] = [$frame, $state, $remoteLabel];
            },
            static function (): void {},
        );

        $link = new FakePeerLink();
        $acceptor->accept($link);

        $frame = new Frame(FrameType::Gossip, 'payload');
        $link->receiveFrame($frame);

        self::assertCount(1, $received);
        self::assertSame($frame, $received[0][0]);
        self::assertInstanceOf(LinkState::class, $received[0][1]);
        self::assertSame(
            $link,
            $received[0][1]->link,
            'the state must carry the accepted link so frameSink can do slot registration without a per-link capture',
        );
        self::assertSame('unknown', $received[0][2], 'a link with no remote() reports the unknown label');
    }

    #[Test]
    public function closeInvokesOnLinkClosedExactlyOnceAndRemovesFromLiveCount(): void
    {
        /** @var list<array{LinkState, PeerLink}> $closedCalls */
        $closedCalls = [];

        $acceptor = new InboundLinkAcceptor(
            $this->runtime,
            10,
            Duration::seconds(10),
            static function (): void {},
            static function (LinkState $state, PeerLink $link) use (&$closedCalls): void {
                $closedCalls[] = [$state, $link];
            },
        );

        $link = new FakePeerLink();
        $acceptor->accept($link);
        self::assertSame(1, $acceptor->liveInboundCount());

        $link->triggerClose();

        self::assertCount(1, $closedCalls, 'onLinkClosed must fire exactly once');
        self::assertSame($link, $closedCalls[0][1]);
        self::assertSame(0, $acceptor->liveInboundCount(), 'the closed link must be removed from the live count');

        // FakePeerLink::triggerClose() is itself idempotent, mirroring a real link's close semantics.
        $link->triggerClose();
        self::assertCount(1, $closedCalls, 'a second close notification must not re-invoke onLinkClosed');
    }

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
    }
}
