<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterDegraded;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\HandshakeResponse;
use Monadial\Nexus\Cluster\Tcp\Membership\MemberRecord;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipService;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipTransition;
use Monadial\Nexus\Cluster\Tcp\Membership\MemberStatus;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeUp;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerSelector;
use Monadial\Nexus\Cluster\Tcp\Membership\PhiAccrualDetector;
use Monadial\Nexus\Cluster\Tcp\Membership\SendGossip;
use Monadial\Nexus\Cluster\Tcp\Membership\SuspicionReason;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Runtime\Duration;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_slice;
use function array_values;

#[CoversClass(MembershipService::class)]
#[CoversClass(MembershipTransition::class)]
#[CoversClass(HandshakeResponse::class)]
#[CoversClass(SendGossip::class)]
#[CoversClass(NodeUp::class)]
#[CoversClass(NodeDown::class)]
#[CoversClass(NodeSuspected::class)]
final class MembershipServiceTest extends TestCase
{
    private TestClock $clock;

    private NodeAddress $peer;

    private NodeEndpoint $peerEndpoint;

    private PhiAccrualDetector $detector;

    private PeerSelector $peerSelector;

    #[Test]
    public function initialStateContainsSelfAsUp(): void
    {
        $t = $this->service()->initialState($this->clock->now());

        self::assertCount(1, $t->newView->upNodes());
        self::assertSame(1, $t->newSelfIncarnation);
        self::assertSame([], $t->events);
        self::assertSame([], $t->effects);
    }

    #[Test]
    public function handshakeAcceptedAddsPeerAndEmitsNodeUp(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyHandshake(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            'production',
            1,
            ClusterView::empty(),
            $this->clock->now(),
            $this->clock->now(),
        );

        self::assertCount(1, $t1->effects);
        $effect = $t1->effects[0];
        self::assertInstanceOf(HandshakeResponse::class, $effect);
        self::assertSame($this->peer, $effect->peer);
        self::assertTrue($effect->accepted);
        self::assertNull($effect->reason);
        self::assertTrue($t1->newView->has($this->peer));
        self::assertCount(1, $t1->events);
        self::assertInstanceOf(NodeUp::class, $t1->events[0]);
    }

    #[Test]
    public function handshakeRejectsClusterNameMismatch(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyHandshake(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            'staging',
            1,
            ClusterView::empty(),
            $this->clock->now(),
            $this->clock->now(),
        );

        self::assertCount(1, $t1->effects);
        $effect = $t1->effects[0];
        self::assertInstanceOf(HandshakeResponse::class, $effect);
        self::assertSame($this->peer, $effect->peer);
        self::assertFalse($effect->accepted);
        self::assertSame('Cluster name mismatch.', $effect->reason);
        self::assertFalse($t1->newView->has($this->peer));
    }

    #[Test]
    public function handshakeRejectsProtocolVersionMismatch(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyHandshake(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            'production',
            2,
            ClusterView::empty(),
            $this->clock->now(),
            $this->clock->now(),
        );

        $effect = $t1->effects[0];
        self::assertInstanceOf(HandshakeResponse::class, $effect);
        self::assertSame($this->peer, $effect->peer);
        self::assertFalse($effect->accepted);
        self::assertSame('Protocol version mismatch.', $effect->reason);
    }

    #[Test]
    public function handshakeMergesPeerView(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $third = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $thirdEndpoint = NodeEndpoint::fromString('10.0.0.3:7355');
        $theirView = ClusterView::empty()->withMember(
            new MemberRecord($third, $thirdEndpoint, 1, MemberStatus::Up, $this->clock->now()),
        );

        $t1 = $service->applyHandshake(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            'production',
            1,
            $theirView,
            $this->clock->now(),
            $this->clock->now(),
        );

        self::assertTrue($t1->newView->has($third));
        // events[0] = NodeUp(peer) from recordLiveness; events[1] = NodeUp(third) from mergeView
        self::assertCount(2, $t1->events);
        $event = $t1->events[1];
        self::assertInstanceOf(NodeUp::class, $event);
        self::assertEquals($third, $event->node);
    }

    #[Test]
    public function livenessFeedsTheDetectorAtObservedTimeNotProcessingTime(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $peer = new NodeAddress('production', 'eu', 'payments', 'node-9');
        $endpoint = NodeEndpoint::fromString('10.0.0.9:7355');

        // Bytes arrived at observedAt; the membership actor only processed the
        // message 8s later (simulating data-plane scheduler contention).
        $observedAt = new DateTimeImmutable('2026-07-10 00:00:02.000000');
        $processingNow = new DateTimeImmutable('2026-07-10 00:00:10.000000');

        $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $peer,
            $endpoint,
            $observedAt,
            $processingNow,
        );

        // The detector recorded the arrival at observedAt: elapsed-since is 0 AT observedAt.
        self::assertSame(
            0.0,
            $this->detector->millisSinceLastHeartbeat($peer->toPathPrefix(), $observedAt),
        );
    }

    #[Test]
    public function handshakeFeedsTheDetectorAtObservedTimeNotProcessingTime(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $peer = new NodeAddress('production', 'eu', 'payments', 'node-9');
        $endpoint = NodeEndpoint::fromString('10.0.0.9:7355');

        // The handshake bytes arrived at observedAt; the membership actor only processed the message
        // 8s later (simulating membership-mailbox contention). The detector must record observedAt.
        $observedAt = new DateTimeImmutable('2026-07-10 00:00:02.000000');
        $processingNow = new DateTimeImmutable('2026-07-10 00:00:10.000000');

        $service->applyHandshake(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $peer,
            $endpoint,
            'production',
            1,
            ClusterView::empty(),
            $observedAt,
            $processingNow,
        );

        self::assertSame(
            0.0,
            $this->detector->millisSinceLastHeartbeat($peer->toPathPrefix(), $observedAt),
        );
    }

    #[Test]
    public function mergeViewEmitsNodeUpWhenSuspectPeerRecovers(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $third = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $thirdEndpoint = NodeEndpoint::fromString('10.0.0.3:7355');

        // Establish third as Up then suspect it via unexpected link close.
        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $third,
            $thirdEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyLinkClosed(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $third,
            false,
            $this->clock->now(),
        );

        // A handshake from peer carries node-3 as Up with a higher incarnation (rejoin).
        $theirView = ClusterView::empty()->withMember(
            new MemberRecord($third, $thirdEndpoint, 2, MemberStatus::Up, $this->clock->now()),
        );
        $t3 = $service->applyHandshake(
            $t2->newView,
            $t2->newSuspectSince,
            $t2->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            'production',
            1,
            $theirView,
            $this->clock->now(),
            $this->clock->now(),
        );

        // events[0] = NodeUp(peer) from recordLiveness; events[1] = NodeUp(third) from status-change detection
        self::assertCount(2, $t3->events);
        $event = $t3->events[1];
        self::assertInstanceOf(NodeUp::class, $event);
        self::assertEquals($third, $event->node);
        self::assertSame(MemberStatus::Up, $t3->newView->members[$third->toPathPrefix()]->status);
    }

    #[Test]
    public function gossipMergeLearnsNewNodes(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $third = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $payload = new GossipPayload(
            members: [
                [
                    'address' => $third->toPathPrefix(),
                    'endpoint' => '10.0.0.3:7355',
                    'incarnation' => 1,
                    'status' => MemberStatus::Up->rank(),
                ],
            ],
            registrations: [],
        );

        $t1 = $service->applyGossip(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $payload,
            $this->clock->now(),
        );

        self::assertTrue($t1->newView->has($third));
        self::assertCount(1, $t1->events);
        self::assertInstanceOf(NodeUp::class, $t1->events[0]);
    }

    #[Test]
    public function gossipPropagatesSuspectStatus(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        // Establish node-3 as Up locally first.
        $third = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $thirdEndpoint = NodeEndpoint::fromString('10.0.0.3:7355');
        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $third,
            $thirdEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );

        // A gossip arrives saying node-3 is Suspect at the same incarnation.
        $payload = new GossipPayload(
            members: [
                [
                    'address' => $third->toPathPrefix(),
                    'endpoint' => '10.0.0.3:7355',
                    'incarnation' => 1,
                    'status' => MemberStatus::Suspect->rank(),
                ],
            ],
            registrations: [],
        );

        $t2 = $service->applyGossip(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $payload,
            $this->clock->now(),
        );

        self::assertSame(MemberStatus::Suspect, $t2->newView->members[$third->toPathPrefix()]->status);
        self::assertCount(1, $t2->events);
        self::assertInstanceOf(NodeSuspected::class, $t2->events[0]);
        self::assertSame(SuspicionReason::Gossip, $t2->events[0]->reason);
    }

    #[Test]
    public function gossipMergeLearnsNewSuspectMemberEmitsNodeSuspected(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $third = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $now = $this->clock->now();
        $payload = new GossipPayload(
            members: [
                [
                    'address' => $third->toPathPrefix(),
                    'endpoint' => '10.0.0.3:7355',
                    'incarnation' => 1,
                    'status' => MemberStatus::Suspect->rank(),
                ],
            ],
            registrations: [],
        );

        $t1 = $service->applyGossip($t0->newView, $t0->newSuspectSince, $t0->newSelfIncarnation, $payload, $now);

        self::assertTrue($t1->newView->has($third));
        self::assertSame(MemberStatus::Suspect, $t1->newView->members[$third->toPathPrefix()]->status);
        self::assertCount(1, $t1->events);
        self::assertInstanceOf(NodeSuspected::class, $t1->events[0]);
        self::assertSame(SuspicionReason::Gossip, $t1->events[0]->reason);
        self::assertArrayHasKey($third->toPathPrefix(), $t1->newSuspectSince);
    }

    #[Test]
    public function gossipMergeLearnsNewDownMemberEmitsNodeDown(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $third = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $payload = new GossipPayload(
            members: [
                [
                    'address' => $third->toPathPrefix(),
                    'endpoint' => '10.0.0.3:7355',
                    'incarnation' => 1,
                    'status' => MemberStatus::Down->rank(),
                ],
            ],
            registrations: [],
        );

        $t1 = $service->applyGossip(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $payload,
            $this->clock->now(),
        );

        self::assertFalse($t1->newView->has($third));
        self::assertCount(1, $t1->events);
        self::assertInstanceOf(NodeDown::class, $t1->events[0]);
        self::assertArrayNotHasKey($third->toPathPrefix(), $t1->newSuspectSince);
    }

    #[Test]
    public function unexpectedLinkCloseSuspectsPeer(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyLinkClosed(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->peer,
            false,
            $this->clock->now(),
        );

        self::assertSame(MemberStatus::Suspect, $t2->newView->members[$this->peer->toPathPrefix()]->status);
        self::assertCount(1, $t2->events);
        self::assertInstanceOf(NodeSuspected::class, $t2->events[0]);
        self::assertSame(SuspicionReason::Connection, $t2->events[0]->reason);
    }

    #[Test]
    public function intentionalLinkCloseDoesNotSuspectPeer(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyLinkClosed(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->peer,
            true,
            $this->clock->now(),
        );

        self::assertSame(MemberStatus::Up, $t2->newView->members[$this->peer->toPathPrefix()]->status);
        self::assertSame([], $t2->events);
    }

    #[Test]
    public function phiThresholdMovesPeerToSuspect(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );

        for ($i = 0; $i < 5; $i++) {
            $this->clock->set($this->clock->now()->modify('+1000 milliseconds'));
            $t = $service->applyLiveness(
                $t->newView,
                $t->newSuspectSince,
                $t->newSelfIncarnation,
                $this->detector,
                $this->peer,
                null,
                $this->clock->now(),
                $this->clock->now(),
            );
        }

        $this->clock->set($this->clock->now()->modify('+6000 milliseconds'));
        $t = $service->applyTick(
            $t->newView,
            $t->newSuspectSince,
            $t->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertSame(MemberStatus::Suspect, $t->newView->members[$this->peer->toPathPrefix()]->status);
        self::assertCount(1, $t->events);
        self::assertInstanceOf(NodeSuspected::class, $t->events[0]);
        self::assertSame(SuspicionReason::Phi, $t->events[0]->reason);
    }

    #[Test]
    public function suspectPeerRecoversOnFrame(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyLinkClosed(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->peer,
            false,
            $this->clock->now(),
        );
        $t3 = $service->applyLiveness(
            $t2->newView,
            $t2->newSuspectSince,
            $t2->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );

        self::assertSame(MemberStatus::Up, $t3->newView->members[$this->peer->toPathPrefix()]->status);
        self::assertCount(1, $t3->events);
        self::assertInstanceOf(NodeUp::class, $t3->events[0]);
    }

    #[Test]
    public function suspectPeerGoesDownAfterGiveUpWindow(): void
    {
        $service = $this->service(downAfter: Duration::seconds(10));
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyLinkClosed(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->peer,
            false,
            $this->clock->now(),
        );

        $this->clock->set($this->clock->now()->modify('+11 seconds'));
        $t3 = $service->applyTick(
            $t2->newView,
            $t2->newSuspectSince,
            $t2->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertFalse($t3->newView->has($this->peer));
        self::assertCount(1, $t3->events);
        self::assertInstanceOf(NodeDown::class, $t3->events[0]);
    }

    #[Test]
    public function silentPeerGoesSuspectThenDownWithoutAnyLinkClose(): void
    {
        // B5: the pure non-EOF heartbeat-timeout path. A peer handshakes (one liveness beat), then
        // stops heart-beating while its socket stays open — no applyLinkClosed is ever called. The
        // give-up window must still drive it Up → Suspect → Down purely from the absence of beats.
        // This is the class of bug already fixed once (recv-timeout misread as EOF / phi starvation):
        // the surviving path that relies on NO socket signal at all.
        $service = $this->service(downAfter: Duration::seconds(10));
        $t0 = $service->initialState($this->clock->now());

        // Single liveness beat at T0 — the handshake. After this the peer is silent forever.
        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        self::assertSame(MemberStatus::Up, $t1->newView->members[$this->peer->toPathPrefix()]->status);

        // T0+11s: silent longer than downAfter — tick must suspect it (absolute-silence fallback,
        // reason Silence, NOT Connection since there was no link close, and NOT Gossip since no
        // peer reported it — this node gave up on it directly).
        $this->clock->set($this->clock->now()->modify('+11 seconds'));
        $t2 = $service->applyTick(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertSame(MemberStatus::Suspect, $t2->newView->members[$this->peer->toPathPrefix()]->status);
        $suspected = array_filter($t2->events, static fn($e): bool => $e instanceof NodeSuspected);
        self::assertCount(1, $suspected);
        self::assertSame(SuspicionReason::Silence, array_values($suspected)[0]->reason);

        // T0+22s: Suspect held longer than downAfter — tick must down and evict it.
        $this->clock->set($this->clock->now()->modify('+11 seconds'));
        $t3 = $service->applyTick(
            $t2->newView,
            $t2->newSuspectSince,
            $t2->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertFalse($t3->newView->has($this->peer), 'the silent peer must be evicted after the give-up window');
        $downs = array_filter($t3->events, static fn($e): bool => $e instanceof NodeDown);
        self::assertCount(1, $downs);
    }

    #[Test]
    public function belowQuorumHoldsTheSuspectInsteadOfDowningItAndEmitsClusterDegraded(): void
    {
        // Floor of 2: with only self reachable (peer is Suspect), the node is below quorum and must
        // NOT evict the peer — otherwise a minority partition would declare the majority Down.
        $service = $this->service(downAfter: Duration::seconds(10), minimumMembers: 2);
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyLinkClosed(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->peer,
            false,
            $this->clock->now(),
        );

        $this->clock->set($this->clock->now()->modify('+11 seconds'));
        $t3 = $service->applyTick(
            $t2->newView,
            $t2->newSuspectSince,
            $t2->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertTrue($t3->newView->has($this->peer), 'the peer is held, not evicted, below quorum');
        self::assertNotEmpty(array_filter($t3->events, static fn($e): bool => $e instanceof ClusterDegraded));
        self::assertEmpty(array_filter($t3->events, static fn($e): bool => $e instanceof NodeDown));
    }

    #[Test]
    public function suspectPeerStaysWithinGiveUpWindow(): void
    {
        $service = $this->service(downAfter: Duration::seconds(10));
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyLinkClosed(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->peer,
            false,
            $this->clock->now(),
        );

        $this->clock->set($this->clock->now()->modify('+5 seconds'));
        $t3 = $service->applyTick(
            $t2->newView,
            $t2->newSuspectSince,
            $t2->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertTrue($t3->newView->has($this->peer));
    }

    #[Test]
    public function leaveRemovesPeerAndEmitsNodeDown(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyLeave($t1->newView, $t1->newSuspectSince, $t1->newSelfIncarnation, $this->peer);

        self::assertFalse($t2->newView->has($this->peer));
        self::assertCount(1, $t2->events);
        self::assertInstanceOf(NodeDown::class, $t2->events[0]);
    }

    #[Test]
    public function tickGossipsToSelectedPeers(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $other = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyLiveness(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->detector,
            $other,
            NodeEndpoint::fromString('10.0.0.3:7355'),
            $this->clock->now(),
            $this->clock->now(),
        );
        $t3 = $service->applyTick(
            $t2->newView,
            $t2->newSuspectSince,
            $t2->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertCount(1, $t3->effects);
        $effect = $t3->effects[0];
        self::assertInstanceOf(SendGossip::class, $effect);
        self::assertCount(2, $effect->targets);
        self::assertContains($this->peer->toPathPrefix(), $effect->targets);
    }

    /**
     * A SUSPECT member must remain a gossip target: if every peer that suspects a node
     * stops gossiping to it, the node can never see itself asserted Suspect, never bumps
     * its incarnation, and the refutation that ends a stale-suspicion epidemic becomes
     * unreachable (observed as persistent Suspect/Up flapping in a 16-node mesh).
     */
    #[Test]
    public function tickStillGossipsToSuspectMembers(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );

        // Unexpected link close marks the peer Suspect (reason: Connection).
        $t2 = $service->applyLinkClosed(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->peer,
            false,
            $this->clock->now(),
        );

        $t3 = $service->applyTick(
            $t2->newView,
            $t2->newSuspectSince,
            $t2->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertCount(1, $t3->effects);
        $effect = $t3->effects[0];
        self::assertInstanceOf(SendGossip::class, $effect);
        self::assertContains(
            $this->peer->toPathPrefix(),
            $effect->targets,
            'the suspected peer must still receive gossip so it can refute',
        );
    }

    #[Test]
    public function tickGossipPayloadContainsMemberAddresses(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peer,
            $this->peerEndpoint,
            $this->clock->now(),
            $this->clock->now(),
        );
        $t2 = $service->applyTick(
            $t1->newView,
            $t1->newSuspectSince,
            $t1->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertCount(1, $t2->effects);
        $effect = $t2->effects[0];
        self::assertInstanceOf(SendGossip::class, $effect);
        $addresses = array_column($effect->payload->members, 'address');
        self::assertContains($this->peer->toPathPrefix(), $addresses);
    }

    #[Test]
    public function tickWithNoPeersProducesNoGossip(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $t1 = $service->applyTick(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $this->peerSelector,
            $this->clock->now(),
        );

        self::assertSame([], $t1->effects);
    }

    #[Test]
    public function rejoinBumpsSelfIncarnation(): void
    {
        $service = $this->service();
        $self = new NodeAddress('production', 'eu', 'payments', 'node-1');
        $t0 = $service->initialState($this->clock->now());
        $before = $t0->newView->members[$self->toPathPrefix()]->incarnation;

        $t1 = $service->applyRejoin(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->clock->now(),
        );

        self::assertSame($before + 1, $t1->newView->members[$self->toPathPrefix()]->incarnation);
        self::assertSame($before + 1, $t1->newSelfIncarnation);
    }

    #[Test]
    public function gossipAssertingSelfSuspectTriggersIncarnationRefutation(): void
    {
        $service = $this->service();
        $self = new NodeAddress('production', 'eu', 'payments', 'node-1');
        $t0 = $service->initialState($this->clock->now());

        // A peer gossips that WE are Suspect at our current incarnation.
        $payload = new GossipPayload(
            members: [
                [
                    'address' => $self->toPathPrefix(),
                    'endpoint' => '10.0.0.1:7355',
                    'incarnation' => 1,
                    'status' => MemberStatus::Suspect->rank(),
                ],
            ],
            registrations: [],
        );

        $t1 = $service->applyGossip(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $payload,
            $this->clock->now(),
        );

        // We refute: bump our incarnation and re-assert Up, so our next gossip wins the merge.
        self::assertSame(2, $t1->newSelfIncarnation);
        self::assertSame(2, $t1->newView->members[$self->toPathPrefix()]->incarnation);
        self::assertSame(MemberStatus::Up, $t1->newView->members[$self->toPathPrefix()]->status);
    }

    #[Test]
    public function refutationFloorsBumpAbovePeerAssertedIncarnation(): void
    {
        $service = $this->service();
        $self = new NodeAddress('production', 'eu', 'payments', 'node-1');
        $t0 = $service->initialState($this->clock->now());

        // A peer holds us at a HIGHER incarnation than we currently know (e.g. after a restart
        // reset our counter to 1). The refutation must clear their value, not just add one to ours.
        $payload = new GossipPayload(
            members: [
                [
                    'address' => $self->toPathPrefix(),
                    'endpoint' => '10.0.0.1:7355',
                    'incarnation' => 5,
                    'status' => MemberStatus::Down->rank(),
                ],
            ],
            registrations: [],
        );

        $t1 = $service->applyGossip(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation, // 1
            $payload,
            $this->clock->now(),
        );

        self::assertSame(6, $t1->newSelfIncarnation, 'bump must floor above the peer-asserted incarnation');
        self::assertSame(6, $t1->newView->members[$self->toPathPrefix()]->incarnation);
        self::assertSame(MemberStatus::Up, $t1->newView->members[$self->toPathPrefix()]->status);
    }

    #[Test]
    public function refutationClampsAtPhpIntMaxInsteadOfOverflowingToFloat(): void
    {
        $service = $this->service();
        $self = new NodeAddress('production', 'eu', 'payments', 'node-1');
        $t0 = $service->initialState($this->clock->now());

        // A peer asserts us Down at PHP_INT_MAX (forged/maxed). The refutation floor + applyRejoin's
        // +1 would overflow to float; instead we must pin at PHP_INT_MAX and stay an int.
        $payload = new GossipPayload(
            members: [
                [
                    'address' => $self->toPathPrefix(),
                    'endpoint' => '10.0.0.1:7355',
                    'incarnation' => PHP_INT_MAX,
                    'status' => MemberStatus::Down->rank(),
                ],
            ],
            registrations: [],
        );

        $t1 = $service->applyGossip(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $payload,
            $this->clock->now(),
        );

        self::assertIsInt($t1->newSelfIncarnation);
        self::assertSame(PHP_INT_MAX, $t1->newSelfIncarnation);
        self::assertSame(PHP_INT_MAX, $t1->newView->members[$self->toPathPrefix()]->incarnation);
        self::assertSame(MemberStatus::Up, $t1->newView->members[$self->toPathPrefix()]->status);
    }

    #[Test]
    public function gossipAssertingSelfUpDoesNotBumpIncarnation(): void
    {
        $service = $this->service();
        $self = new NodeAddress('production', 'eu', 'payments', 'node-1');
        $t0 = $service->initialState($this->clock->now());

        // Gossip that echoes us as Up (the normal case) must NOT trigger a refutation.
        $payload = new GossipPayload(
            members: [
                [
                    'address' => $self->toPathPrefix(),
                    'endpoint' => '10.0.0.1:7355',
                    'incarnation' => 1,
                    'status' => MemberStatus::Up->rank(),
                ],
            ],
            registrations: [],
        );

        $t1 = $service->applyGossip(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $payload,
            $this->clock->now(),
        );

        self::assertSame(1, $t1->newSelfIncarnation, 'a self-Up echo must not bump the incarnation');
        self::assertSame(1, $t1->newView->members[$self->toPathPrefix()]->incarnation);
    }

    protected function setUp(): void
    {
        $this->clock = new TestClock();
        $this->peer = new NodeAddress('production', 'eu', 'payments', 'node-2');
        $this->peerEndpoint = NodeEndpoint::fromString('10.0.0.2:7355');
        $this->detector = new PhiAccrualDetector();
        $this->peerSelector = new class implements PeerSelector {
            #[Override]
            public function select(array $peers, int $count): array
            {
                return array_slice($peers, 0, $count);
            }
        };
    }

    private function service(?Duration $downAfter = null, int $minimumMembers = 0): MembershipService
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: new NodeAddress('production', 'eu', 'payments', 'node-1'),
            bindEndpoint: NodeEndpoint::fromString('127.0.0.1:7355'),
            advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
            seeds: [NodeEndpoint::fromString('10.0.0.9:7355')],
        );

        if ($minimumMembers > 0) {
            $topology = $topology->withMinimumMembers($minimumMembers);
        }

        return new MembershipService($topology, $downAfter);
    }
}
