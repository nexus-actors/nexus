<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Connection;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Connection\ConnectionReport;
use Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\ClearTombstone;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\EvictPeer;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\LinkClosed;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\LinkReport;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RecordTombstone;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterIdentifiedLink;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterUnauthenticatedEndpoint;
use Monadial\Nexus\Cluster\Tcp\Connection\RoutingSnapshotHolder;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\HandshakeReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLinkClosed;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerDisconnected;
use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Monadial\Nexus\Cluster\Tcp\MutableEndpointRegistry;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\FakePeerLink;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingEventDispatcher;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingMeter;
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

#[CoversClass(ConnectionSupervisor::class)]
final class ConnectionSupervisorTest extends TestCase
{
    private StepRuntime $runtime;

    private ActorSystem $system;

    private MutableEndpointRegistry $endpointRegistry;

    private RoutingSnapshotHolder $snapshotHolder;

    private RecordingEventDispatcher $dispatcher;

    private TcpAskRegistry $askRegistry;

    private RecordingMeter $meter;

    /** @var list<object> */
    private array $membershipInbox = [];

    /** @var list<string> */
    private array $forgottenPrefixes = [];

    /** @var list<NodeEndpoint> */
    private array $evictedEndpoints = [];

    private NodeAddress $peer;

    private NodeEndpoint $peerEndpoint;

    private int $refSeq = 0;

    #[Test]
    public function registerIdentifiedLinkRegistersEndpointPublishesSnapshotAndTellsMembership(): void
    {
        $ref = $this->spawnSupervisor();
        $link = new FakePeerLink();

        $ref->tell($this->registerIdentifiedLink($link));
        $this->runtime->drain();

        self::assertSame($this->peerEndpoint, $this->endpointRegistry->resolve($this->peer));

        $snapshot = $this->snapshotHolder->current();
        self::assertSame($link, $snapshot->acceptedLinks[$this->peer->toPathPrefix()]);
        self::assertSame(1, $snapshot->generation);
        self::assertArrayNotHasKey($this->peer->toPathPrefix(), $snapshot->verifiedPrefixes);

        self::assertCount(1, $this->membershipInbox);
        self::assertInstanceOf(HandshakeReceived::class, $this->membershipInbox[0]);
        self::assertSame($this->peer, $this->membershipInbox[0]->origin);
        self::assertSame($this->peerEndpoint, $this->membershipInbox[0]->endpoint);
    }

    #[Test]
    public function registerIdentifiedLinkWithNullLinkSkipsAcceptedSlotButStillRegistersAndTellsMembership(): void
    {
        $ref = $this->spawnSupervisor();

        $ref->tell($this->registerIdentifiedLink(null));
        $this->runtime->drain();

        self::assertSame($this->peerEndpoint, $this->endpointRegistry->resolve($this->peer));
        self::assertArrayNotHasKey($this->peer->toPathPrefix(), $this->snapshotHolder->current()->acceptedLinks);
        self::assertCount(1, $this->membershipInbox);
        self::assertInstanceOf(HandshakeReceived::class, $this->membershipInbox[0]);
    }

    #[Test]
    public function registerIdentifiedLinkDoesNotMarkVerifiedPrefixWhenAuthenticationIsDisabled(): void
    {
        $ref = $this->spawnSupervisor(authenticationEnabled: false);
        $ref->tell($this->registerIdentifiedLink(new FakePeerLink()));
        $this->runtime->drain();

        self::assertArrayNotHasKey($this->peer->toPathPrefix(), $this->snapshotHolder->current()->verifiedPrefixes);
    }

    #[Test]
    public function registerIdentifiedLinkMarksVerifiedPrefixWhenAuthenticationIsEnabled(): void
    {
        $ref = $this->spawnSupervisor(authenticationEnabled: true);
        $ref->tell($this->registerIdentifiedLink(new FakePeerLink()));
        $this->runtime->drain();

        self::assertArrayHasKey($this->peer->toPathPrefix(), $this->snapshotHolder->current()->verifiedPrefixes);
    }

    #[Test]
    public function registerIdentifiedLinkClearsAnExistingTombstoneForTheSamePrefix(): void
    {
        $ref = $this->spawnSupervisor();
        $prefix = $this->peer->toPathPrefix();

        $ref->tell(new RecordTombstone($prefix));
        $this->runtime->drain();
        self::assertArrayHasKey($prefix, $this->snapshotHolder->current()->tombstones);

        $ref->tell($this->registerIdentifiedLink(new FakePeerLink()));
        $this->runtime->drain();

        self::assertArrayNotHasKey($prefix, $this->snapshotHolder->current()->tombstones);
    }

    #[Test]
    public function linkClosedRemovesTheAcceptedSlotTombstonesAndNotifiesEverySideEffect(): void
    {
        $ref = $this->spawnSupervisor();
        $link = new FakePeerLink();

        $ref->tell($this->registerIdentifiedLink($link));
        $this->runtime->drain();
        $this->membershipInbox = [];

        $correlationId = $this->registerPendingAsk();

        $ref->tell(new LinkClosed($this->peer, $link));
        $this->runtime->drain();

        $prefix = $this->peer->toPathPrefix();
        $snapshot = $this->snapshotHolder->current();
        self::assertArrayNotHasKey($prefix, $snapshot->acceptedLinks);
        self::assertArrayHasKey($prefix, $snapshot->tombstones);

        self::assertSame([$prefix], $this->forgottenPrefixes);

        self::assertCount(1, $this->membershipInbox);
        self::assertInstanceOf(PeerLinkClosed::class, $this->membershipInbox[0]);
        self::assertFalse($this->membershipInbox[0]->intentional);

        $disconnected = $this->dispatcher->ofType(PeerDisconnected::class);
        self::assertCount(1, $disconnected);
        self::assertSame($this->peer, $disconnected[0]->peer);

        self::assertFalse($this->askRegistry->has($correlationId), 'the pending ask must be failed on link close');
    }

    #[Test]
    public function linkClosedIdentityGuardLeavesANewerSupersedingSlotIntact(): void
    {
        $ref = $this->spawnSupervisor();
        $staleLink = new FakePeerLink();
        $newLink = new FakePeerLink();

        // First handshake accepts $staleLink; a re-handshake (C10 supersede) replaces the slot
        // with $newLink without ever closing $staleLink.
        $ref->tell($this->registerIdentifiedLink($staleLink));
        $this->runtime->drain();
        $ref->tell($this->registerIdentifiedLink($newLink));
        $this->runtime->drain();

        $prefix = $this->peer->toPathPrefix();
        self::assertSame($newLink, $this->snapshotHolder->current()->acceptedLinks[$prefix]);

        // The stale link's own onClose fires LinkClosed with the OLD link object — it must not
        // clobber the newer slot or tombstone an actually-still-connected peer.
        $ref->tell(new LinkClosed($this->peer, $staleLink));
        $this->runtime->drain();

        $snapshot = $this->snapshotHolder->current();
        self::assertSame($newLink, $snapshot->acceptedLinks[$prefix], 'the newer slot must survive a stale close');
        self::assertArrayNotHasKey($prefix, $snapshot->tombstones, 'a superseded close must not tombstone a live peer');

        // Side effects (throttle forget, membership tell, dispatch, ask failure) still fire
        // unconditionally, matching pre-actorization onLinkClosed semantics.
        self::assertSame([$prefix], $this->forgottenPrefixes);
        self::assertCount(1, $this->dispatcher->ofType(PeerDisconnected::class));
    }

    #[Test]
    public function recordTombstoneIsIdempotentAndDoesNotRepublishOnADuplicate(): void
    {
        $ref = $this->spawnSupervisor();
        $prefix = $this->peer->toPathPrefix();

        $ref->tell(new RecordTombstone($prefix));
        $this->runtime->drain();
        $generationAfterFirst = $this->snapshotHolder->current()->generation;

        $ref->tell(new RecordTombstone($prefix));
        $this->runtime->drain();

        self::assertArrayHasKey($prefix, $this->snapshotHolder->current()->tombstones);
        self::assertSame($generationAfterFirst, $this->snapshotHolder->current()->generation);
    }

    #[Test]
    public function clearTombstoneIsANoOpWhenThePrefixIsNotTombstoned(): void
    {
        $ref = $this->spawnSupervisor();
        $generationBefore = $this->snapshotHolder->current()->generation;

        $ref->tell(new ClearTombstone('/cluster/never/tombstoned/x/y'));
        $this->runtime->drain();

        self::assertSame($generationBefore, $this->snapshotHolder->current()->generation);
    }

    #[Test]
    public function clearTombstoneRemovesAnExistingTombstone(): void
    {
        $ref = $this->spawnSupervisor();
        $prefix = $this->peer->toPathPrefix();

        $ref->tell(new RecordTombstone($prefix));
        $this->runtime->drain();
        self::assertArrayHasKey($prefix, $this->snapshotHolder->current()->tombstones);

        $ref->tell(new ClearTombstone($prefix));
        $this->runtime->drain();

        self::assertArrayNotHasKey($prefix, $this->snapshotHolder->current()->tombstones);
    }

    #[Test]
    public function registerUnauthenticatedEndpointRegistersAFreshPrefix(): void
    {
        $ref = $this->spawnSupervisor();
        $prefix = $this->peer->toPathPrefix();

        $ref->tell(new RegisterUnauthenticatedEndpoint($prefix, $this->peerEndpoint, 'gossip_endpoint_authority'));
        $this->runtime->drain();

        self::assertSame($this->peerEndpoint, $this->endpointRegistry->resolveByPrefix($prefix));
    }

    #[Test]
    public function registerUnauthenticatedEndpointRefusesAConflictingClaimAgainstAVerifiedPrefixAndCountsIt(): void
    {
        $ref = $this->spawnSupervisor(authenticationEnabled: true);
        $ref->tell($this->registerIdentifiedLink(new FakePeerLink()));
        $this->runtime->drain();

        $prefix = $this->peer->toPathPrefix();
        $conflicting = NodeEndpoint::fromString('10.0.0.99:9999');

        $ref->tell(new RegisterUnauthenticatedEndpoint($prefix, $conflicting, 'gossip_endpoint_authority'));
        $this->runtime->drain();

        // The verified Handshake's endpoint must survive the conflicting unauthenticated claim.
        self::assertSame($this->peerEndpoint, $this->endpointRegistry->resolveByPrefix($prefix));

        $rejected = $this->meter->counters['nexus.cluster.control.rejected'] ?? null;
        self::assertNotNull($rejected);
        self::assertSame(1, (int) $rejected->total);
        self::assertSame('gossip_endpoint_authority', $rejected->adds[0]['attributes']['check']);
    }

    #[Test]
    public function registerUnauthenticatedEndpointAllowsAMatchingClaimSilentlyWithoutCountingARejection(): void
    {
        $ref = $this->spawnSupervisor(authenticationEnabled: true);
        $ref->tell($this->registerIdentifiedLink(new FakePeerLink()));
        $this->runtime->drain();

        $prefix = $this->peer->toPathPrefix();

        // Re-announcing the SAME endpoint value is steady-state gossip noise, not an attack.
        $ref->tell(new RegisterUnauthenticatedEndpoint($prefix, $this->peerEndpoint, 'gossip_endpoint_authority'));
        $this->runtime->drain();

        self::assertSame($this->peerEndpoint, $this->endpointRegistry->resolveByPrefix($prefix));
        self::assertArrayNotHasKey('nexus.cluster.control.rejected', $this->meter->counters);
    }

    #[Test]
    public function evictPeerEvictsTheResolvedEndpointFromThePool(): void
    {
        $ref = $this->spawnSupervisor();
        $ref->tell($this->registerIdentifiedLink(new FakePeerLink()));
        $this->runtime->drain();

        $ref->tell(new EvictPeer($this->peer));
        $this->runtime->drain();

        self::assertSame([$this->peerEndpoint], $this->evictedEndpoints);
    }

    #[Test]
    public function evictPeerIsANoOpWhenTheEndpointIsUnknown(): void
    {
        $ref = $this->spawnSupervisor();

        $ref->tell(new EvictPeer($this->peer));
        $this->runtime->drain();

        self::assertSame([], $this->evictedEndpoints);
    }

    #[Test]
    public function linkReportReturnsTheCurrentCounts(): void
    {
        $ref = $this->spawnSupervisor();
        $link = new FakePeerLink();

        $ref->tell($this->registerIdentifiedLink($link));
        $this->runtime->drain();

        $future = $ref->ask(new LinkReport(), Duration::seconds(5));
        $this->runtime->drain();

        /** @var ConnectionReport $report */
        $report = $future->await();

        self::assertSame([$this->peer->toPathPrefix()], $report->acceptedPrefixes);
        self::assertSame(0, $report->tombstoneCount);
        self::assertSame(1, $report->endpointCount);
        self::assertSame(1, $report->generation);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->runtime = new StepRuntime();
        $this->system = ActorSystem::create(
            'connection-supervisor-test',
            $this->runtime,
            clock: $this->runtime->clock(),
        );
        $this->endpointRegistry = new MutableEndpointRegistry();
        $this->snapshotHolder = new RoutingSnapshotHolder();
        $this->dispatcher = new RecordingEventDispatcher();
        $this->askRegistry = new TcpAskRegistry($this->runtime);
        $this->meter = new RecordingMeter();
        $this->membershipInbox = [];
        $this->forgottenPrefixes = [];
        $this->evictedEndpoints = [];
        $this->peer = new NodeAddress('production', 'eu', 'payments', 'node-2');
        $this->peerEndpoint = NodeEndpoint::fromString('10.0.0.2:7355');
    }

    private function registerIdentifiedLink(?FakePeerLink $link): RegisterIdentifiedLink
    {
        return new RegisterIdentifiedLink(
            peer: $this->peer,
            endpoint: $this->peerEndpoint,
            boundAdvertise: (string) $this->peerEndpoint,
            link: $link,
            handshake: new Handshake(
                'production',
                [
                    'application' => $this->peer->application,
                    'cluster' => $this->peer->cluster,
                    'datacenter' => $this->peer->datacenter,
                    'node' => $this->peer->node,
                ],
                (string) $this->peerEndpoint,
            ),
            observedAt: $this->runtime->clock()->now(),
        );
    }

    /**
     * Register a pending ask targeting {@see $this->peer} so a test can observe {@see
     * \Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry::failAllForNode()} firing on link close.
     */
    private function registerPendingAsk(): string
    {
        $correlationId = 'corr-1';
        // The returned Future is intentionally never awaited: nothing suspends on it in this test,
        // and TcpAskRegistry::failAllForNode() only needs the pending registration to exist.
        $this->askRegistry->register(
            $correlationId,
            Duration::seconds(5),
            $this->peer->temporaryAskReplyPath('req-1'),
            $this->peer,
        );

        return $correlationId;
    }

    /**
     * @return ActorRef<object>
     */
    private function spawnSupervisor(bool $authenticationEnabled = false): ActorRef
    {
        $supervisor = new ConnectionSupervisor(
            endpointRegistry: $this->endpointRegistry,
            snapshotHolder: $this->snapshotHolder,
            membershipRef: $this->spawnMembershipProbe(),
            dispatcher: $this->dispatcher,
            askRegistry: $this->askRegistry,
            forgetThrottle: function (string $prefix): void {
                $this->forgottenPrefixes[] = $prefix;
            },
            evictFromPool: function (NodeEndpoint $endpoint): void {
                $this->evictedEndpoints[] = $endpoint;
            },
            authenticationEnabled: $authenticationEnabled,
            meter: $this->meter,
        );

        return $this->system->spawn($supervisor->props(), 'cluster-connections');
    }

    /**
     * @return ActorRef<object>
     */
    private function spawnMembershipProbe(): ActorRef
    {
        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(
            /**
             * @param ActorContext<object> $ctx
             * @return Behavior<object>
             */
            function (ActorContext $ctx, object $msg): Behavior {
                $this->membershipInbox[] = $msg;

                return Behavior::same();
            },
        );

        return $this->system->spawn(Props::fromBehavior($behavior), 'membership-probe-' . $this->refSeq++);
    }
}
