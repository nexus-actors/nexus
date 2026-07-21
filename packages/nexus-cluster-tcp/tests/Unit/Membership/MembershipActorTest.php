<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\HandshakeResponse;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipActor;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipService;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipState;
use Monadial\Nexus\Cluster\Tcp\Membership\MemberStatus;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GetClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GossipReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GossipTick;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\HandshakeReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\HeartbeatTick;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\LeaveReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLinkClosed;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLivenessObserved;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeUp;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerSelector;
use Monadial\Nexus\Cluster\Tcp\Membership\PhiAccrualDetector;
use Monadial\Nexus\Cluster\Tcp\Membership\SendGossip;
use Monadial\Nexus\Cluster\Tcp\Membership\SuspicionReason;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingEffectInterpreter;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingEventPublisher;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function count;

#[CoversClass(MembershipActor::class)]
#[CoversClass(MembershipState::class)]
final class MembershipActorTest extends TestCase
{
    private StepRuntime $runtime;

    private ActorSystem $system;

    private RecordingEffectInterpreter $effects;

    private RecordingEventPublisher $events;

    private NodeAddress $peer;

    private NodeEndpoint $peerEndpoint;

    private int $probeSeq = 0;

    #[Test]
    public function initialViewContainsSelfAsUp(): void
    {
        $ref = $this->spawnActor();

        $view = $this->queryView($ref);

        self::assertCount(1, $view->upNodes());
        self::assertTrue($view->has(new NodeAddress('production', 'eu', 'payments', 'node-1')));
    }

    #[Test]
    public function handshakeAddsPeerUpAndProducesHandshakeResponse(): void
    {
        $ref = $this->spawnActor();

        $ref->tell($this->handshakeFromPeer());
        $this->runtime->drain();

        $upEvents = $this->events->ofType(NodeUp::class);
        self::assertCount(1, $upEvents);
        self::assertTrue($upEvents[0]->node->toPathPrefix() === $this->peer->toPathPrefix());

        $responses = $this->effects->ofType(HandshakeResponse::class);
        self::assertCount(1, $responses);
        self::assertTrue($responses[0]->accepted);

        $view = $this->queryView($ref);
        self::assertTrue($view->has($this->peer));
        self::assertSame(MemberStatus::Up, $view->members[$this->peer->toPathPrefix()]->status);
    }

    #[Test]
    public function unexpectedLinkCloseMovesPeerToSuspectAndPublishesNodeSuspected(): void
    {
        $ref = $this->spawnActor();
        $ref->tell($this->handshakeFromPeer());
        $this->runtime->drain();
        $this->events->clear();
        $this->effects->clear();

        $ref->tell(new PeerLinkClosed($this->peer, intentional: false));
        $this->runtime->drain();

        $suspected = $this->events->ofType(NodeSuspected::class);
        self::assertCount(1, $suspected);
        self::assertSame(SuspicionReason::Connection, $suspected[0]->reason);

        $view = $this->queryView($ref);
        self::assertSame(MemberStatus::Suspect, $view->members[$this->peer->toPathPrefix()]->status);
    }

    #[Test]
    public function intentionalLinkClosePublishesNothingAndLeavesPeerUp(): void
    {
        $ref = $this->spawnActor();
        $ref->tell($this->handshakeFromPeer());
        $this->runtime->drain();
        $this->events->clear();
        $this->effects->clear();

        $ref->tell(new PeerLinkClosed($this->peer, intentional: true));
        $this->runtime->drain();

        self::assertSame([], $this->events->events());
        self::assertSame([], $this->effects->effects());

        $view = $this->queryView($ref);
        self::assertSame(MemberStatus::Up, $view->members[$this->peer->toPathPrefix()]->status);
    }

    #[Test]
    public function heartbeatTickEmitsSendGossipForUpPeers(): void
    {
        $ref = $this->spawnActor();
        $ref->tell($this->handshakeFromPeer());
        $this->runtime->drain();
        $this->effects->clear();

        $ref->tell(new HeartbeatTick());
        $this->runtime->drain();

        $gossip = $this->effects->ofType(SendGossip::class);
        self::assertNotEmpty($gossip);
        self::assertContains($this->peer->toPathPrefix(), $gossip[0]->targets);
    }

    #[Test]
    public function gossipTickEmitsSendGossipForUpPeers(): void
    {
        $ref = $this->spawnActor();
        $ref->tell($this->handshakeFromPeer());
        $this->runtime->drain();
        $this->effects->clear();

        $ref->tell(new GossipTick());
        $this->runtime->drain();

        self::assertNotEmpty($this->effects->ofType(SendGossip::class));
    }

    #[Test]
    public function scheduledTicksFireUnderRuntimeAndEmitGossip(): void
    {
        $ref = $this->spawnActor(heartbeatInterval: Duration::seconds(1), gossipInterval: Duration::seconds(5));
        $ref->tell($this->handshakeFromPeer());
        $this->runtime->drain();
        $this->effects->clear();

        // No tick has fired yet: handshake only produced a HandshakeResponse.
        self::assertSame([], $this->effects->ofType(SendGossip::class));

        $this->runtime->advanceTime(Duration::seconds(1));
        $this->runtime->drain();

        self::assertNotEmpty($this->effects->ofType(SendGossip::class));
    }

    #[Test]
    public function timersStopFiringAfterActorIsStopped(): void
    {
        $ref = $this->spawnActor(heartbeatInterval: Duration::seconds(1), gossipInterval: Duration::seconds(1));
        $ref->tell($this->handshakeFromPeer());
        $this->runtime->drain();

        // Confirm gossip fires while alive.
        $this->runtime->advanceTime(Duration::seconds(1));
        $this->runtime->drain();
        self::assertNotEmpty($this->effects->ofType(SendGossip::class));
        $this->effects->clear();

        // Stop the actor. PostStop cancels both Cancellables.
        $this->system->stop($ref);
        $this->runtime->drain();

        // Advance past the tick interval. Cancelled timers must not enqueue HeartbeatTick
        // or GossipTick, so no new SendGossip effects can be produced.
        $this->runtime->advanceTime(Duration::seconds(1));
        $this->runtime->drain();

        self::assertSame([], $this->effects->ofType(SendGossip::class));
    }

    #[Test]
    public function livenessObservedRecoversSuspectPeerToUpAndPublishesNodeUp(): void
    {
        $ref = $this->spawnActor();
        $ref->tell($this->handshakeFromPeer());
        $this->runtime->drain();

        // Move peer to Suspect via unexpected link close.
        $ref->tell(new PeerLinkClosed($this->peer, intentional: false));
        $this->runtime->drain();

        $view = $this->queryView($ref);
        self::assertSame(MemberStatus::Suspect, $view->members[$this->peer->toPathPrefix()]->status);

        $this->events->clear();

        // PeerLivenessObserved for a known peer (endpoint null = already tracked).
        $ref->tell(new PeerLivenessObserved($this->peer, null, $this->runtime->clock()->now()));
        $this->runtime->drain();

        // Peer must recover to Up and emit NodeUp.
        $upEvents = $this->events->ofType(NodeUp::class);
        self::assertCount(1, $upEvents);
        self::assertSame($this->peer->toPathPrefix(), $upEvents[0]->node->toPathPrefix());

        $view = $this->queryView($ref);
        self::assertSame(MemberStatus::Up, $view->members[$this->peer->toPathPrefix()]->status);
    }

    #[Test]
    public function gossipReceivedMergesNewMemberAndPublishesNodeUp(): void
    {
        $ref = $this->spawnActor();
        $this->events->clear();

        $thirdPeer = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $gossip = new GossipPayload(
            [
                [
                    'address' => $thirdPeer->toPathPrefix(),
                    'endpoint' => '10.0.0.3:7355',
                    'incarnation' => 1,
                    'status' => 1,
                ],
            ],
            [],
        );

        $ref->tell(new GossipReceived($this->peer, $gossip));
        $this->runtime->drain();

        // View must now include the gossiped member.
        $view = $this->queryView($ref);
        self::assertTrue($view->has($thirdPeer));
        self::assertSame(MemberStatus::Up, $view->members[$thirdPeer->toPathPrefix()]->status);

        // A NodeUp event must have been published for the newly learned member.
        $upEvents = $this->events->ofType(NodeUp::class);
        self::assertCount(1, $upEvents);
        self::assertSame($thirdPeer->toPathPrefix(), $upEvents[0]->node->toPathPrefix());
    }

    /**
     * Gossip echo dedup: after a member goes Down, stale gossip re-teaching the same
     * incarnation re-adds it to the VIEW (correct — the merge is a join-semilattice)
     * but must NOT re-announce it — in a 16-node mesh this readmission churn amplified
     * one real departure into dozens of NodeSuspected/NodeDown events per observer.
     */
    #[Test]
    public function staleGossipReaddingADownedMemberDoesNotReannounce(): void
    {
        $ref = $this->spawnActor();
        $this->events->clear();

        $thirdPeer = new NodeAddress('production', 'eu', 'payments', 'node-3');
        $upGossip = new GossipPayload(
            [
                [
                    'address' => $thirdPeer->toPathPrefix(),
                    'endpoint' => '10.0.0.3:7355',
                    'incarnation' => 1,
                    'status' => 1,
                ],
            ],
            [],
        );

        // Learn the member, then it leaves: NodeUp + NodeDown announced.
        $ref->tell(new GossipReceived($this->peer, $upGossip));
        $ref->tell(new LeaveReceived($thirdPeer));
        $this->runtime->drain();

        self::assertCount(1, $this->events->ofType(NodeUp::class));
        self::assertCount(1, $this->events->ofType(NodeDown::class));

        // Stale gossip still circulating the pre-departure suspicion re-adds the member.
        $staleGossip = new GossipPayload(
            [
                [
                    'address' => $thirdPeer->toPathPrefix(),
                    'endpoint' => '10.0.0.3:7355',
                    'incarnation' => 1,
                    'status' => 2,
                ],
            ],
            [],
        );
        $ref->tell(new GossipReceived($this->peer, $staleGossip));
        $this->runtime->drain();

        // The view may readmit it (merge semantics), but subscribers hear nothing new.
        self::assertCount(
            0,
            $this->events->ofType(NodeSuspected::class),
            'post-Down readmission churn must be suppressed, not re-announced',
        );
    }

    #[Test]
    public function stateEvolvesAcrossMessages(): void
    {
        $ref = $this->spawnActor();

        $ref->tell($this->handshakeFromPeer());
        $this->runtime->drain();
        self::assertCount(2, $this->queryView($ref)->members);

        $this->events->clear();
        $ref->tell(new LeaveReceived($this->peer));
        $this->runtime->drain();

        self::assertCount(1, $this->events->ofType(NodeDown::class));
        $view = $this->queryView($ref);
        self::assertCount(1, $view->members);
        self::assertFalse($view->has($this->peer));

        // selfIncarnation carries across the multi-message sequence: self must still be incarnation 1.
        $self = new NodeAddress('production', 'eu', 'payments', 'node-1');
        self::assertSame(1, $view->members[$self->toPathPrefix()]->incarnation);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->runtime = new StepRuntime();
        $this->system = ActorSystem::create('membership-test', $this->runtime, clock: $this->runtime->clock());
        $this->effects = new RecordingEffectInterpreter();
        $this->events = new RecordingEventPublisher();
        $this->peer = new NodeAddress('production', 'eu', 'payments', 'node-2');
        $this->peerEndpoint = NodeEndpoint::fromString('10.0.0.2:7355');
    }

    private function handshakeFromPeer(): HandshakeReceived
    {
        return new HandshakeReceived(
            $this->peer,
            $this->peerEndpoint,
            new Handshake(
                'production',
                [
                    'application' => $this->peer->application,
                    'cluster' => $this->peer->cluster,
                    'datacenter' => $this->peer->datacenter,
                    'node' => $this->peer->node,
                ],
                (string) $this->peerEndpoint,
            ),
            $this->runtime->clock()->now(),
        );
    }

    /**
     * @return ActorRef<object>
     */
    private function spawnActor(?Duration $heartbeatInterval = null, ?Duration $gossipInterval = null): ActorRef
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: new NodeAddress('production', 'eu', 'payments', 'node-1'),
            bindEndpoint: NodeEndpoint::fromString('127.0.0.1:7355'),
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

        $actor = new MembershipActor(
            new MembershipService($topology),
            new PhiAccrualDetector(),
            $selector,
            $this->effects,
            $this->events,
            $this->runtime->clock(),
            $heartbeatInterval,
            $gossipInterval,
        );

        $ref = $this->system->spawn($actor->props(), 'membership');
        $this->runtime->drain();

        return $ref;
    }

    /**
     * @param ActorRef<object> $ref
     */
    private function queryView(ActorRef $ref): ClusterView
    {
        $captured = [];

        /** @var Behavior<ClusterView> $behavior */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
                if ($msg instanceof ClusterView) {
                    $captured[] = $msg;
                }

                return Behavior::same();
            },
        );

        /** @var ActorRef<ClusterView> $probe */
        $probe = $this->system->spawn(Props::fromBehavior($behavior), 'probe-' . $this->probeSeq++);

        $ref->tell(new GetClusterView($probe));
        $this->runtime->drain();

        self::assertGreaterThan(0, count($captured), 'GetClusterView produced no reply.');

        return $captured[count($captured) - 1];
    }
}
