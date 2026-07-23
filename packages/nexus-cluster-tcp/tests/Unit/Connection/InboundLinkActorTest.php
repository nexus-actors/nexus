<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Connection;

use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\EvictPeer;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\LinkClosed;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RecordTombstone;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterIdentifiedLink;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterUnauthenticatedEndpoint;
use Monadial\Nexus\Cluster\Tcp\Connection\RoutingSnapshot;
use Monadial\Nexus\Cluster\Tcp\Connection\RoutingSnapshotHolder;
use Monadial\Nexus\Cluster\Tcp\DeliveryOutcome;
use Monadial\Nexus\Cluster\Tcp\Membership\HandshakeAuthenticator;
use Monadial\Nexus\Cluster\Tcp\Membership\LivenessThrottle;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipService;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GossipReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\LeaveReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLivenessObserved;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerAuthenticator;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerConnected;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterMessageCodec;
use Monadial\Nexus\Cluster\Tcp\Messaging\InboxRouter;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalActorRegistry;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalDelivery;
use Monadial\Nexus\Cluster\Tcp\Messaging\NoopTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Messaging\OutboundSink;
use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Cluster\Tcp\Protocol\FrameType;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\FakePeerLink;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingEventDispatcher;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingLogger;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingMeter;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\SpyTracer;
use Monadial\Nexus\Cluster\Tcp\Transport\LinkClosedNotice;
use Monadial\Nexus\Cluster\Tcp\Transport\LinkFrame;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function hrtime;

/**
 * Unit tests for the actorized per-link frame state machine: the Unidentified→Identified
 * `become`, the Slowloris `ReceiveTimeout`, C2 (zero pre-identification ingress), and the
 * moved-verbatim SEC-008 admission/ack/gossip/leave branches.
 *
 * Frames are delivered the same way the real pump does: wrapped in a {@see LinkFrame} and
 * `tell()`'d directly to the spawned actor's ref — not via `PeerLink::onFrame()`, which
 * `InboundLinkAcceptor` (not this actor) wires. `FakePeerLink` here only stands in for the
 * link the actor closes/registers, via {@see FakePeerLink::wasClosed()}.
 */
#[CoversClass(InboundLinkActor::class)]
final class InboundLinkActorTest extends TestCase
{
    private StepRuntime $runtime;

    private ActorSystem $system;

    private RoutingSnapshotHolder $snapshotHolder;

    private LivenessThrottle $livenessThrottle;

    private ControlFrameCodec $controlCodec;

    private MessagePayloadCodec $payloadCodec;

    private InboxRouter $inboxRouter;

    private RecordingEventDispatcher $dispatcher;

    private RecordingMeter $meter;

    private SpyTracer $tracer;

    private NodeAddress $self;

    private NodeAddress $peer;

    private NodeEndpoint $peerEndpoint;

    /** @var list<object> */
    private array $supervisorInbox = [];

    /** @var list<object> */
    private array $membershipInbox = [];

    /** @var list<array{string, Frame}> */
    private array $egressCalls = [];

    private DeliveryOutcome $egressOutcome = DeliveryOutcome::Admitted;

    private int $refSeq = 0;

    // -------------------------------------------------------------------------
    // Unidentified -> Identified (become)
    // -------------------------------------------------------------------------

    #[Test]
    public function aValidHandshakeRegistersTheLinkAndBecomesIdentified(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));

        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();

        self::assertCount(1, $this->supervisorInbox);
        self::assertInstanceOf(RegisterIdentifiedLink::class, $this->supervisorInbox[0]);
        self::assertEquals($this->peer, $this->supervisorInbox[0]->peer);
        self::assertSame((string) $this->peerEndpoint, (string) $this->supervisorInbox[0]->endpoint);
        self::assertSame($link, $this->supervisorInbox[0]->link);

        self::assertCount(1, $this->dispatcher->events);
        self::assertInstanceOf(PeerConnected::class, $this->dispatcher->events[0]);

        // Now Identified: a Gossip frame is processed (proves the become landed), unlike the
        // pre-identification silence below. GossipReceived always fires; PeerLivenessObserved
        // fires too since this is the peer's first liveness observation (throttle always passes
        // the first call).
        $this->send($ref, $this->gossipFrame([]));
        $this->runtime->drain();

        self::assertCount(2, $this->membershipInbox);
        self::assertInstanceOf(GossipReceived::class, $this->membershipInbox[0]);
        self::assertInstanceOf(PeerLivenessObserved::class, $this->membershipInbox[1]);
    }

    #[Test]
    public function aMalformedHandshakeIsRejectedAndTheLinkStaysUnidentified(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));

        $this->send($ref, new Frame(FrameType::Handshake, 'not-a-valid-handshake'));
        $this->runtime->drain();

        self::assertSame([], $this->supervisorInbox);

        $rejected = $this->meter->counters['nexus.cluster.handshake.rejected'] ?? null;
        self::assertNotNull($rejected);
        self::assertSame(1, (int) $rejected->total);

        // Still Unidentified: a subsequent valid handshake still identifies successfully.
        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();

        self::assertCount(1, $this->supervisorInbox);
    }

    #[Test]
    public function nonHandshakeFramesAreSilentlyDroppedBeforeIdentification(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));

        $this->send($ref, $this->gossipFrame([]));
        $this->send($ref, new Frame(FrameType::Message, ''));
        $this->send($ref, $this->leaveFrame($this->peer));
        $this->runtime->drain();

        self::assertSame([], $this->supervisorInbox, 'C2: zero pre-identification ingress');
        self::assertSame([], $this->membershipInbox);
        self::assertFalse($link->wasClosed());
    }

    #[Test]
    public function handshakeDeadlineClosesTheLinkAndStopsWhileUnidentified(): void
    {
        $link = new FakePeerLink();
        $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(5));

        $this->runtime->advanceTime(Duration::seconds(5));
        $this->runtime->drain();

        self::assertTrue($link->wasClosed());
    }

    /**
     * Regression (review Critical): the deadline must be HARD. A `setReceiveTimeout`-based
     * implementation resets on every user message, so an unauthenticated peer trickling junk
     * non-Handshake frames at intervals just under the deadline would defer it forever — each
     * junk frame is silently dropped (C2), so neither the bounded mailbox nor the acceptor's
     * Dropped-enqueue flood bound trips either. The self-scheduled
     * {@see \Monadial\Nexus\Cluster\Tcp\Transport\HandshakeDeadline} must fire at the ORIGINAL
     * deadline regardless of intervening traffic.
     */
    #[Test]
    public function aTrickleOfJunkFramesDoesNotDeferTheHandshakeDeadline(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(5));

        // Junk non-Handshake frames every 2s — each interval shorter than the 5s deadline, the
        // span (6s) exceeding it. A resettable timeout would never fire under this traffic.
        foreach ([0, 2, 4] as $atSecond) {
            $this->send($ref, $this->gossipFrame([]));
            $this->runtime->drain();
            self::assertFalse($link->wasClosed(), "must not close before the deadline (t={$atSecond}s)");
            $this->runtime->advanceTime(Duration::seconds(2));
            $this->runtime->drain();
        }

        // t=6s > the 5s hard deadline: closed despite the continuous junk trickle.
        self::assertTrue($link->wasClosed(), 'the hard deadline must fire despite trickle traffic');
        self::assertSame([], $this->supervisorInbox, 'C2 held throughout: junk produced no ingress');
        self::assertSame([], $this->membershipInbox);
    }

    #[Test]
    public function handshakeDeadlineIsCancelledOnceIdentified(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(5));

        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();

        $this->runtime->advanceTime(Duration::seconds(10));
        $this->runtime->drain();

        self::assertFalse($link->wasClosed(), 'the Slowloris deadline must not fire once identified');

        // The actor is still alive and Identified: a Gossip frame is still processed.
        $this->send($ref, $this->gossipFrame([]));
        $this->runtime->drain();
        self::assertCount(2, $this->membershipInbox);
        self::assertInstanceOf(GossipReceived::class, $this->membershipInbox[0]);
    }

    #[Test]
    public function noHandshakeDeadlineIsArmedWhenNoneIsConfigured(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: null);

        $this->runtime->advanceTime(Duration::seconds(3_600));
        $this->runtime->drain();

        self::assertFalse($link->wasClosed(), 'the dialed-outbound path has no Slowloris deadline');

        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        self::assertCount(1, $this->supervisorInbox);
    }

    #[Test]
    public function linkClosedNoticeWhileUnidentifiedStopsWithoutTellingTheSupervisor(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));

        $ref->tell(new LinkClosedNotice());
        $this->runtime->drain();

        self::assertSame([], $this->supervisorInbox, 'not yet identified: no peer address to report');
    }

    // -------------------------------------------------------------------------
    // Identified: re-handshake (SEC-008 checks 1-2 actually bite here)
    // -------------------------------------------------------------------------

    #[Test]
    public function aSamePrefixReHandshakeReRegistersAndStaysIdentified(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));

        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        self::assertCount(1, $this->supervisorInbox);

        // Endpoint failover: same identity, new advertise endpoint.
        $newEndpoint = NodeEndpoint::fromString('10.0.0.9:7355');
        $this->send($ref, $this->handshakeFrame(endpoint: $newEndpoint));
        $this->runtime->drain();

        self::assertCount(2, $this->supervisorInbox);
        self::assertInstanceOf(RegisterIdentifiedLink::class, $this->supervisorInbox[1]);
        self::assertEquals($this->peer, $this->supervisorInbox[1]->peer);
        self::assertSame((string) $newEndpoint, (string) $this->supervisorInbox[1]->endpoint);

        self::assertFalse($link->wasClosed());
    }

    #[Test]
    public function aConflictingReHandshakeIsRejectedAndCounted(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));

        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        self::assertCount(1, $this->supervisorInbox);

        $impostor = new NodeAddress('test', 'dc', 'app', 'impostor');
        $this->send($ref, $this->handshakeFrame(peer: $impostor));
        $this->runtime->drain();

        // No second registration — the mismatched handshake was rejected.
        self::assertCount(1, $this->supervisorInbox);

        $rejected = $this->meter->counters['nexus.cluster.control.rejected'] ?? null;
        self::assertNotNull($rejected);
        self::assertSame('reidentify_mismatch', $rejected->adds[0]['attributes']['check']);

        // The link is still bound to the ORIGINAL identity: a Gossip frame still routes as that peer.
        $this->send($ref, $this->gossipFrame([]));
        $this->runtime->drain();
        self::assertCount(2, $this->membershipInbox);
        self::assertEquals($this->peer, $this->membershipInbox[0]->origin);
    }

    // -------------------------------------------------------------------------
    // Identified: HandshakeAck / Gossip / Leave / Message
    // -------------------------------------------------------------------------

    #[Test]
    public function handshakeAckRegistersUnauthenticatedEndpointsForNewEntries(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));
        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        $this->supervisorInbox = [];

        $third = new NodeAddress('test', 'dc', 'app', 'third');
        $thirdEndpoint = NodeEndpoint::fromString('10.0.0.5:7355');
        $ack = new HandshakeAck(true, null, [$third->toPathPrefix() => (string) $thirdEndpoint]);
        $this->send($ref, new Frame(FrameType::HandshakeAck, $this->controlCodec->packHandshakeAck($ack)));
        $this->runtime->drain();

        self::assertCount(1, $this->supervisorInbox);
        self::assertInstanceOf(RegisterUnauthenticatedEndpoint::class, $this->supervisorInbox[0]);
        self::assertSame($third->toPathPrefix(), $this->supervisorInbox[0]->prefix);
    }

    #[Test]
    public function handshakeAckSkipsATombstonedEntry(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));
        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        $this->supervisorInbox = [];

        $departed = new NodeAddress('test', 'dc', 'app', 'departed');
        $this->snapshotHolder->publish(new RoutingSnapshot(
            endpoints: [],
            tombstones: [$departed->toPathPrefix() => true],
            verifiedPrefixes: [],
            acceptedLinks: [],
            generation: 1,
        ));

        $ack = new HandshakeAck(true, null, [$departed->toPathPrefix() => '10.0.0.6:7355']);
        $this->send($ref, new Frame(FrameType::HandshakeAck, $this->controlCodec->packHandshakeAck($ack)));
        $this->runtime->drain();

        self::assertSame([], $this->supervisorInbox, 'a departed peer must not be resurrected via ack view');
    }

    #[Test]
    public function aGossipFrameTellsMembershipAndFeedsLivenessOnce(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));
        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        $this->membershipInbox = [];

        $this->send($ref, $this->gossipFrame([]));
        $this->send($ref, $this->gossipFrame([]));
        $this->runtime->drain();

        self::assertCount(
            3,
            $this->membershipInbox,
            'both frames route GossipReceived; only the FIRST also feeds liveness',
        );
        self::assertInstanceOf(GossipReceived::class, $this->membershipInbox[0]);
        self::assertInstanceOf(PeerLivenessObserved::class, $this->membershipInbox[1]);
        self::assertInstanceOf(GossipReceived::class, $this->membershipInbox[2]);
    }

    #[Test]
    public function aLeaveFrameTombstonesTellsMembershipEvictsAndRelays(): void
    {
        $leaver = new NodeAddress('test', 'dc', 'app', 'leaver');
        $other = new NodeAddress('test', 'dc', 'app', 'other-accepted-peer');

        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));
        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        $this->supervisorInbox = [];
        $this->membershipInbox = [];

        // Simulate the supervisor's own accepted-link directory (relay target) alongside the
        // sender's own accepted slot.
        $otherLink = new FakePeerLink();
        $this->snapshotHolder->publish(new RoutingSnapshot(
            endpoints: [],
            tombstones: [],
            verifiedPrefixes: [],
            acceptedLinks: [
                $other->toPathPrefix() => $otherLink,
                $this->peer->toPathPrefix() => $link,
            ],
            generation: 1,
        ));

        $this->send($ref, $this->leaveFrame($leaver));
        $this->runtime->drain();

        self::assertCount(2, $this->supervisorInbox);
        self::assertInstanceOf(RecordTombstone::class, $this->supervisorInbox[0]);
        self::assertSame($leaver->toPathPrefix(), $this->supervisorInbox[0]->prefix);
        self::assertInstanceOf(EvictPeer::class, $this->supervisorInbox[1]);
        self::assertEquals($leaver, $this->supervisorInbox[1]->peer);

        self::assertCount(1, $this->membershipInbox);
        self::assertInstanceOf(LeaveReceived::class, $this->membershipInbox[0]);
        self::assertEquals($leaver, $this->membershipInbox[0]->origin);

        // Relayed to the OTHER accepted peer, but not back to the sender or the leaver.
        self::assertCount(1, $this->egressCalls);
        self::assertSame($other->toPathPrefix(), $this->egressCalls[0][0]);
    }

    #[Test]
    public function anAlreadyTombstonedLeaveIsDeduplicated(): void
    {
        $leaver = new NodeAddress('test', 'dc', 'app', 'leaver');

        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));
        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        $this->supervisorInbox = [];
        $this->membershipInbox = [];

        $this->snapshotHolder->publish(new RoutingSnapshot(
            endpoints: [],
            tombstones: [$leaver->toPathPrefix() => true],
            verifiedPrefixes: [],
            acceptedLinks: [],
            generation: 1,
        ));

        $this->send($ref, $this->leaveFrame($leaver));
        $this->runtime->drain();

        self::assertSame([], $this->supervisorInbox);
        self::assertSame([], $this->membershipInbox);
        self::assertSame([], $this->egressCalls);
    }

    #[Test]
    public function anUnsignedLeaveIsRejectedWhenAuthenticationIsEnabled(): void
    {
        $authenticator = $this->authenticator();
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10), authenticator: $authenticator);
        $this->send($ref, $this->handshakeFrame(authenticator: $authenticator));
        $this->runtime->drain();
        $this->supervisorInbox = [];
        $this->membershipInbox = [];

        // Unsigned Leave (no nonce/issuedAt/mac).
        $leaver = new NodeAddress('test', 'dc', 'app', 'leaver');
        $this->send($ref, new Frame(
            FrameType::Leave,
            $this->controlCodec->packLeave(new LeavePayload($leaver->toPathPrefix())),
        ));
        $this->runtime->drain();

        self::assertSame([], $this->supervisorInbox);
        self::assertSame([], $this->membershipInbox);

        $rejected = $this->meter->counters['nexus.cluster.control.rejected'] ?? null;
        self::assertNotNull($rejected);
        self::assertSame('leave_unsigned', $rejected->adds[0]['attributes']['check']);
    }

    #[Test]
    public function aSignedLeaveIsAdmittedWhenAuthenticationIsEnabled(): void
    {
        $authenticator = $this->authenticator();
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10), authenticator: $authenticator);
        $this->send($ref, $this->handshakeFrame(authenticator: $authenticator));
        $this->runtime->drain();
        $this->supervisorInbox = [];
        $this->membershipInbox = [];

        $leaver = new NodeAddress('test', 'dc', 'app', 'leaver');
        $signed = $authenticator->signLeave(new LeavePayload($leaver->toPathPrefix()));
        $this->send($ref, new Frame(FrameType::Leave, $this->controlCodec->packLeave($signed)));
        $this->runtime->drain();

        self::assertCount(2, $this->supervisorInbox);
        self::assertCount(1, $this->membershipInbox);
    }

    #[Test]
    public function aMessageFrameIsIngestedAndFeedsLiveness(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));
        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        $this->membershipInbox = [];

        // Garbage payload: FrameIngress fails to decode it and swallows the failure, but the
        // liveness observation still fires — proving `onMessage` ran the ingest + liveness pair.
        $this->send($ref, new Frame(FrameType::Message, 'not-a-message-payload'));
        $this->runtime->drain();

        self::assertCount(1, $this->membershipInbox);
        self::assertInstanceOf(PeerLivenessObserved::class, $this->membershipInbox[0]);
    }

    // -------------------------------------------------------------------------
    // Link close
    // -------------------------------------------------------------------------

    #[Test]
    public function linkClosedNoticeWhileIdentifiedTellsTheSupervisorAndStops(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));
        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();
        $this->supervisorInbox = [];

        $ref->tell(new LinkClosedNotice());
        $this->runtime->drain();

        self::assertCount(1, $this->supervisorInbox);
        self::assertInstanceOf(LinkClosed::class, $this->supervisorInbox[0]);
        self::assertEquals($this->peer, $this->supervisorInbox[0]->peer);
        self::assertSame($link, $this->supervisorInbox[0]->link);
    }

    #[Test]
    public function postStopClosesTheLinkWhenIdentifiedAndStoppedDirectly(): void
    {
        $link = new FakePeerLink();
        $ref = $this->spawnActor(link: $link, handshakeTimeout: Duration::seconds(10));
        $this->send($ref, $this->handshakeFrame());
        $this->runtime->drain();

        // Not via ReceiveTimeout or LinkClosedNotice — a plain external stop still closes the link.
        $this->system->stop($ref);
        $this->runtime->drain();

        self::assertTrue($link->wasClosed());
    }

    #[Test]
    public function postStopIsANoOpForTheDialedOutboundVariantWithoutALink(): void
    {
        $outboundRef = $this->spawnActor(link: null, handshakeTimeout: null);

        $this->system->stop($outboundRef);
        $this->runtime->drain();

        // No exception — PostStop on a link-less actor is a no-op ($this->link?->close()).
        self::assertFalse($outboundRef->isAlive());
    }

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->runtime = new StepRuntime();
        $this->system = ActorSystem::create('inbound-link-actor-test', $this->runtime, clock: $this->runtime->clock());

        $this->snapshotHolder = new RoutingSnapshotHolder();
        $this->livenessThrottle = new LivenessThrottle();
        $this->controlCodec = new ControlFrameCodec();
        $this->payloadCodec = new MessagePayloadCodec();
        $this->dispatcher = new RecordingEventDispatcher();
        $this->meter = new RecordingMeter();
        $this->tracer = new SpyTracer();

        $this->supervisorInbox = [];
        $this->membershipInbox = [];
        $this->egressCalls = [];
        $this->egressOutcome = DeliveryOutcome::Admitted;

        $this->self = new NodeAddress('test', 'dc', 'app', 'self-node');
        $this->peer = new NodeAddress('test', 'dc', 'app', 'peer-node');
        $this->peerEndpoint = NodeEndpoint::fromString('10.0.0.2:7355');

        $this->inboxRouter = new InboxRouter(
            new LocalDelivery(new LocalActorRegistry()),
            new TcpAskRegistry($this->runtime),
            new ClusterMessageCodec(new MessagePackMessageSerializer(new TypeRegistry()), new TypeRegistry()),
            new class implements OutboundSink {
                public function send(NodeAddress $target, MessagePayload $payload): DeliveryOutcome
                {
                    return DeliveryOutcome::Dropped;
                }
            },
            new NoopTraceContextExtractor(),
        );
    }

    /**
     * Simulate the pump: stamp `$frame` (C3 — see class docblock) and hand it to the actor
     * exactly as `InboundLinkAcceptor`/`ClusterNode::dialSeed()` would, via `tell()` rather than
     * `PeerLink::onFrame()` (which only the pump wires, not this actor).
     */
    private function send(ActorRef $ref, Frame $frame): void
    {
        $ref->tell(new LinkFrame($frame, $this->runtime->clock()->now(), hrtime(true)));
    }

    private function handshakeFrame(
        ?NodeAddress $peer = null,
        ?NodeEndpoint $endpoint = null,
        ?PeerAuthenticator $authenticator = null,
    ): Frame {
        $peer ??= $this->peer;
        $endpoint ??= $this->peerEndpoint;

        $handshake = new Handshake(
            clusterName: 'test',
            node: [
                'application' => $peer->application,
                'cluster' => $peer->cluster,
                'datacenter' => $peer->datacenter,
                'node' => $peer->node,
            ],
            advertise: (string) $endpoint,
            protocolVersion: MembershipService::PROTOCOL_VERSION,
        );
        $handshake = $authenticator?->sign($handshake) ?? $handshake;

        return new Frame(FrameType::Handshake, $this->controlCodec->packHandshake($handshake));
    }

    /**
     * @param list<array{address: string, endpoint: string, incarnation: int, status: int}> $members
     */
    private function gossipFrame(array $members): Frame
    {
        return new Frame(FrameType::Gossip, $this->controlCodec->packGossip(new GossipPayload($members, [])));
    }

    private function leaveFrame(NodeAddress $leaver): Frame
    {
        return new Frame(FrameType::Leave, $this->controlCodec->packLeave(new LeavePayload($leaver->toPathPrefix())));
    }

    private function authenticator(): PeerAuthenticator
    {
        // Deliberately NOT the StepRuntime's virtual clock (fixed at 2026-01-01): sign() uses the
        // injected clock but the actor's verify()/verifyLeave() calls always use real time() — the
        // documented asymmetry (see ClusterNode/InboundLinkActor). Signing against the virtual
        // clock would fail verify()'s freshness check against real wall-clock time.
        return new HandshakeAuthenticator('shared-secret');
    }

    private function spawnActor(
        ?PeerLink $link,
        ?Duration $handshakeTimeout,
        ?PeerAuthenticator $authenticator = null,
        ?Closure $egress = null,
    ): ActorRef {
        $actor = new InboundLinkActor(
            supervisorRef: $this->probe($this->supervisorInbox),
            membershipRef: $this->probe($this->membershipInbox),
            snapshotHolder: $this->snapshotHolder,
            authenticator: $authenticator,
            topology: $this->topology(),
            payloadCodec: $this->payloadCodec,
            controlCodec: $this->controlCodec,
            inboxRouter: $this->inboxRouter,
            livenessThrottle: $this->livenessThrottle,
            egress: $egress ?? $this->egress(...),
            tracer: $this->tracer,
            meter: $this->meter,
            dispatcher: $this->dispatcher,
            logger: new RecordingLogger(),
            link: $link,
            remoteLabel: $link?->remote() !== null ? (string) $link?->remote() : 'unknown',
            handshakeTimeout: $handshakeTimeout,
        );

        return $this->system->spawn($actor->props(), 'inbound-link-' . $this->refSeq++);
    }

    private function egress(string $prefix, Frame $frame): DeliveryOutcome
    {
        $this->egressCalls[] = [$prefix, $frame];

        return $this->egressOutcome;
    }

    private function topology(): ClusterTopology
    {
        return ClusterTopology::create(
            clusterName: 'test',
            self: $this->self,
            bindEndpoint: NodeEndpoint::fromString('127.0.0.1:1'),
            advertiseEndpoint: NodeEndpoint::fromString('127.0.0.1:1'),
            seeds: [],
            singleNode: true,
        );
    }

    /**
     * @param list<object> $inbox
     * @return ActorRef<object>
     */
    private function probe(array &$inbox): ActorRef
    {
        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(
            /**
             * @param ActorContext<object> $ctx
             * @return Behavior<object>
             */
            static function (ActorContext $ctx, object $msg) use (&$inbox): Behavior {
                $inbox[] = $msg;

                return Behavior::same();
            },
        );

        return $this->system->spawn(Props::fromBehavior($behavior), 'probe-' . $this->refSeq++);
    }
}
