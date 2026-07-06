<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\MemberRecord;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEvent;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipService;
use Monadial\Nexus\Cluster\Tcp\Membership\MemberStatus;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeUp;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerSelector;
use Monadial\Nexus\Cluster\Tcp\Membership\PhiAccrualDetector;
use Monadial\Nexus\Cluster\Tcp\Membership\SuspicionReason;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Runtime\Duration;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;

#[CoversClass(MembershipService::class)]
#[CoversClass(NodeUp::class)]
#[CoversClass(NodeDown::class)]
#[CoversClass(NodeSuspected::class)]
final class MembershipServiceTest extends TestCase
{
    private TestClock $clock;

    private NodeAddress $peer;

    private NodeEndpoint $peerEndpoint;

    /** @var list<MembershipEvent> */
    private array $events = [];

    #[Test]
    public function initialViewContainsSelfAsUp(): void
    {
        $service = $this->service();

        self::assertCount(1, $service->currentView()->upNodes());
    }

    #[Test]
    public function handshakeAcceptedAddsPeerAndEmitsNodeUp(): void
    {
        $service = $this->service();

        $ack = $service->onHandshake($this->peer, $this->peerEndpoint, 'production', 1, ClusterView::empty());

        self::assertTrue($ack->accepted);
        self::assertNull($ack->reason);
        self::assertTrue($service->currentView()->has($this->peer));
        self::assertInstanceOf(NodeUp::class, $this->events[0]);
    }

    #[Test]
    public function handshakeRejectsClusterNameMismatch(): void
    {
        $service = $this->service();

        $ack = $service->onHandshake($this->peer, $this->peerEndpoint, 'staging', 1, ClusterView::empty());

        self::assertFalse($ack->accepted);
        self::assertSame('Cluster name mismatch.', $ack->reason);
        self::assertFalse($service->currentView()->has($this->peer));
    }

    #[Test]
    public function handshakeRejectsProtocolVersionMismatch(): void
    {
        $service = $this->service();

        $ack = $service->onHandshake($this->peer, $this->peerEndpoint, 'production', 2, ClusterView::empty());

        self::assertFalse($ack->accepted);
        self::assertSame('Protocol version mismatch.', $ack->reason);
    }

    #[Test]
    public function handshakeMergesPeerView(): void
    {
        $service = $this->service();
        $third = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $thirdEndpoint = NodeEndpoint::fromString('10.0.0.3:7355');
        $theirView = ClusterView::empty()->withMember(
            new MemberRecord(
                $third,
                $thirdEndpoint,
                1,
                MemberStatus::Up,
                $this->clock->now(),
            ),
        );

        $service->onHandshake($this->peer, $this->peerEndpoint, 'production', 1, $theirView);

        self::assertTrue($service->currentView()->has($third));
        // events[0] = NodeUp(peer) from recordLiveness; events[1] = NodeUp(third) from mergeView
        $event = $this->events[1];
        self::assertInstanceOf(NodeUp::class, $event);
        self::assertEquals($third, $event->node);
    }

    #[Test]
    public function mergeViewEmitsNodeUpWhenSuspectPeerRecovers(): void
    {
        $service = $this->service();
        $third = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $thirdEndpoint = NodeEndpoint::fromString('10.0.0.3:7355');

        // Establish third as Up then suspect it via unexpected link close.
        $service->onFrameFromPeer($third, $thirdEndpoint);
        $service->onLinkClosed($third, intentional: false);
        $this->events = [];

        // A handshake from peer-2 carries node-3 as Up with a higher incarnation (rejoin).
        $theirView = ClusterView::empty()->withMember(
            new MemberRecord($third, $thirdEndpoint, 2, MemberStatus::Up, $this->clock->now()),
        );
        $service->onHandshake($this->peer, $this->peerEndpoint, 'production', 1, $theirView);

        // events[0] = NodeUp(peer) from recordLiveness; events[1] = NodeUp(third) from status-change detection
        $event = $this->events[1];
        self::assertInstanceOf(NodeUp::class, $event);
        self::assertEquals($third, $event->node);
        self::assertSame(MemberStatus::Up, $service->currentView()->members[$third->toPathPrefix()]->status);
    }

    #[Test]
    public function gossipMergeLearnsNewNodes(): void
    {
        $service = $this->service();
        $third = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $payload = new GossipPayload([$third->toPathPrefix() => '10.0.0.3:7355'], []);

        $service->onGossip($this->peer, $payload);

        self::assertTrue($service->currentView()->has($third));
        self::assertInstanceOf(NodeUp::class, $this->events[0]);
    }

    #[Test]
    public function unexpectedLinkCloseSuspectsPeer(): void
    {
        $service = $this->service();
        $service->onFrameFromPeer($this->peer, $this->peerEndpoint);
        $this->events = [];

        $service->onLinkClosed($this->peer, intentional: false);

        self::assertSame(MemberStatus::Suspect, $service->currentView()->members[$this->peer->toPathPrefix()]->status);
        self::assertInstanceOf(NodeSuspected::class, $this->events[0]);
        self::assertSame(SuspicionReason::Connection, $this->events[0]->reason);
    }

    #[Test]
    public function intentionalLinkCloseDoesNotSuspectPeer(): void
    {
        $service = $this->service();
        $service->onFrameFromPeer($this->peer, $this->peerEndpoint);
        $this->events = [];

        $service->onLinkClosed($this->peer, intentional: true);

        self::assertSame(MemberStatus::Up, $service->currentView()->members[$this->peer->toPathPrefix()]->status);
        self::assertSame([], $this->events);
    }

    #[Test]
    public function phiThresholdMovesPeerToSuspect(): void
    {
        $service = $this->service();
        $service->onFrameFromPeer($this->peer, $this->peerEndpoint);

        foreach ([1000, 2000, 3000, 4000, 5000] as $ms) {
            $this->clock->set($this->clock->now()->modify("+1000 milliseconds"));
            $service->onPing($this->peer);
        }

        $this->events = [];
        $this->clock->set($this->clock->now()->modify('+6000 milliseconds'));
        $service->tick($this->clock->now());

        self::assertSame(MemberStatus::Suspect, $service->currentView()->members[$this->peer->toPathPrefix()]->status);
        self::assertInstanceOf(NodeSuspected::class, $this->events[0]);
        self::assertSame(SuspicionReason::Phi, $this->events[0]->reason);
    }

    #[Test]
    public function suspectPeerRecoversOnFrame(): void
    {
        $service = $this->service();
        $service->onFrameFromPeer($this->peer, $this->peerEndpoint);
        $service->onLinkClosed($this->peer, intentional: false);
        $this->events = [];

        $service->onFrameFromPeer($this->peer, $this->peerEndpoint);

        self::assertSame(MemberStatus::Up, $service->currentView()->members[$this->peer->toPathPrefix()]->status);
        self::assertInstanceOf(NodeUp::class, $this->events[0]);
    }

    #[Test]
    public function suspectPeerGoesDownAfterGiveUpWindow(): void
    {
        $service = $this->service(downAfter: Duration::seconds(10));
        $service->onFrameFromPeer($this->peer, $this->peerEndpoint);
        $service->onLinkClosed($this->peer, intentional: false);
        $this->events = [];

        $this->clock->set($this->clock->now()->modify('+11 seconds'));
        $service->tick($this->clock->now());

        self::assertFalse($service->currentView()->has($this->peer));
        self::assertInstanceOf(NodeDown::class, $this->events[0]);
    }

    #[Test]
    public function suspectPeerStaysWithinGiveUpWindow(): void
    {
        $service = $this->service(downAfter: Duration::seconds(10));
        $service->onFrameFromPeer($this->peer, $this->peerEndpoint);
        $service->onLinkClosed($this->peer, intentional: false);

        $this->clock->set($this->clock->now()->modify('+5 seconds'));
        $service->tick($this->clock->now());

        self::assertTrue($service->currentView()->has($this->peer));
    }

    #[Test]
    public function leaveRemovesPeerAndEmitsNodeDown(): void
    {
        $service = $this->service();
        $service->onFrameFromPeer($this->peer, $this->peerEndpoint);
        $this->events = [];

        $service->onLeave($this->peer);

        self::assertFalse($service->currentView()->has($this->peer));
        self::assertInstanceOf(NodeDown::class, $this->events[0]);
    }

    #[Test]
    public function tickGossipsToSelectedPeers(): void
    {
        $service = $this->service();
        $service->onFrameFromPeer($this->peer, $this->peerEndpoint);
        $other = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $service->onFrameFromPeer($other, NodeEndpoint::fromString('10.0.0.3:7355'));

        $payloads = $service->tick($this->clock->now());

        self::assertCount(2, $payloads);
        self::assertArrayHasKey($this->peer->toPathPrefix(), $payloads[0]->view);
    }

    #[Test]
    public function tickWithNoPeersProducesNoGossip(): void
    {
        self::assertSame([], $this->service()->tick($this->clock->now()));
    }

    #[Test]
    public function rejoinBumpsSelfIncarnation(): void
    {
        $service = $this->service();
        $self = new NodeAddress('production', 'eu', 'payments', 'node-1');
        $before = $service->currentView()->members[$self->toPathPrefix()]->incarnation;

        $service->rejoin();

        self::assertSame($before + 1, $service->currentView()->members[$self->toPathPrefix()]->incarnation);
    }

    protected function setUp(): void
    {
        $this->clock = new TestClock();
        $this->peer = new NodeAddress('production', 'eu', 'payments', 'node-2');
        $this->peerEndpoint = NodeEndpoint::fromString('10.0.0.2:7355');
        $this->events = [];
    }

    private function service(?Duration $downAfter = null): MembershipService
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: new NodeAddress('production', 'eu', 'payments', 'node-1'),
            bindEndpoint: NodeEndpoint::fromString('0.0.0.0:7355'),
            advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
            seeds: [NodeEndpoint::fromString('10.0.0.9:7355')],
        );

        $selector = new class implements PeerSelector {
            #[Override]
            public function select(array $peers, int $count): array
            {
                return array_slice($peers, 0, $count);
            }
        };

        $service = new MembershipService($topology, $this->clock, new PhiAccrualDetector(), $selector, $downAfter);
        $service->onViewChange(function (ClusterView $_view, array $events): void {
            foreach ($events as $event) {
                $this->events[] = $event;
            }
        });

        return $service;
    }
}
