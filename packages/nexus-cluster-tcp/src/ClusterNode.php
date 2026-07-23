<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use BadMethodCallException;
use Closure;
use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\EvictPeer;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\LinkClosed;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RecordTombstone;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterIdentifiedLink;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterUnauthenticatedEndpoint;
use Monadial\Nexus\Cluster\Tcp\Connection\RoutingSnapshotHolder;
use Monadial\Nexus\Cluster\Tcp\Membership\AskFailingMembershipEventPublisher;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\DepartedPeerTracker;
use Monadial\Nexus\Cluster\Tcp\Membership\EventDispatcherMembershipEventPublisher;
use Monadial\Nexus\Cluster\Tcp\Membership\HandshakeAuthenticator;
use Monadial\Nexus\Cluster\Tcp\Membership\LivenessThrottle;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipActor;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipService;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GetClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GossipReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\LeaveReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLivenessObserved;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerAuthenticator;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerConnected;
use Monadial\Nexus\Cluster\Tcp\Membership\PhiAccrualDetector;
use Monadial\Nexus\Cluster\Tcp\Membership\ShuffledCycleSelector;
use Monadial\Nexus\Cluster\Tcp\Membership\TcpMembershipEffectInterpreter;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterMessageCodec;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterRef;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterRefFactory;
use Monadial\Nexus\Cluster\Tcp\Messaging\FrameIngress;
use Monadial\Nexus\Cluster\Tcp\Messaging\InboxRouter;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalActorRegistry;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalDelivery;
use Monadial\Nexus\Cluster\Tcp\Messaging\NoopTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Messaging\NoopTraceContextInjector;
use Monadial\Nexus\Cluster\Tcp\Messaging\OutboundSink;
use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Cluster\Tcp\Protocol\FrameType;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextInjector;
use Monadial\Nexus\Cluster\Tcp\Transport\InboundLinkAcceptor;
use Monadial\Nexus\Cluster\Tcp\Transport\LinkState;
use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackHub;
use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Transport\MeshTransport;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerConnection;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerConnectionPool;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;
use Monadial\Nexus\Cluster\Tcp\Transport\Tcp\SwooleMeshTransport;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_keys;
use function array_values;
use function count;
use function explode;
use function extension_loaded;
use function hrtime;
use function ltrim;
use function preg_replace;
use function strlen;
use function time;

/**
 * @psalm-api
 *
 * The cluster node bootstrap: wires every mesh component into a running cluster node.
 *
 * Boot the node after creating the ActorSystem but before calling `$system->run()`. The
 * node's membership actor schedules its own heartbeat / gossip ticks, so once the runtime
 * loop starts everything is self-driving.
 *
 * Transport selection: SwooleMeshTransport when ext-swoole is loaded AND the system runtime
 * is SwooleRuntime; LoopbackMeshTransport with a fresh hub otherwise. Pass an explicit
 * `$transport` to override — this is the hook for multi-node loopback integration tests.
 *
 * Circular boot dependency is resolved with a lazy sender: the `TcpMembershipEffectInterpreter`
 * receives a closure that captures `$selfNode` by reference. `$selfNode` is null during the
 * assembly phase but is set before `$system->run()` fires any actor messages, so the closure
 * always finds a populated node when actually called.
 *
 * Rejoin after Down: there is no in-band `RejoinRequested` message, so a node that transitioned
 * to Down rejoins by restarting — or re-booting with the same identity on a fresh transport — which
 * re-announces itself to peers via the per-connection handshake preamble ({@see handshakePreamble()}).
 * A long-lived seed re-identifies the returning node without operator intervention. This restart-rejoin
 * path is covered by the `departedNodeRejoinsWithSameIdentity` integration test.
 *
 * @example
 *   $runtime = new FiberRuntime();
 *   $system  = ActorSystem::create('my-cluster', $runtime);
 *   $node    = ClusterNode::boot($system, $topology);
 *   $node->expose($ref);
 *   $system->run();
 */
final class ClusterNode
{
    /** SEC-008 controlRejected `check` attribute values — see {@see recordControlRejected()}. */
    private const string CHECK_LEAVE_UNSIGNED = 'leave_unsigned';

    private const string CHECK_LEAVE_REPLAY = 'leave_replay';

    private const string CHECK_REIDENTIFY_MISMATCH = 'reidentify_mismatch';

    private const string CHECK_ACK_VIEW_AUTHORITY = 'ack_view_authority';

    private const string CHECK_GOSSIP_ENDPOINT_AUTHORITY = 'gossip_endpoint_authority';

    /**
     * Set by {@see shutdown()}. Once this node has broadcast its own Leave it must emit no further
     * frames: with the per-connection handshake preamble, any post-Leave gossip/ack that lazily
     * re-dials a peer would re-announce this node's identity and effectively rejoin the mesh it
     * just left. Gating {@see sendByPrefix()} makes graceful departure final.
     */
    private bool $stopped = false;

    private ?Counter $handshakeRejected = null;

    private ?Counter $framesDecodeFailed = null;

    private ?Counter $controlSendFailed = null;

    private ?Counter $controlRejected = null;

    /**
     * The accepted-inbound-link directory, departed-peer tombstones, SEC-008-verified endpoint
     * prefixes, and the (now supervisor-internal) `MutableEndpointRegistry` all moved to {@see
     * ConnectionSupervisor} — this node only ever tells it writes and reads its published {@see
     * Connection\RoutingSnapshot} via {@see $routingSnapshotHolder}.
     */
    private function __construct(
        private readonly NodeAddress $selfAddress,
        private readonly ClusterTopology $topology,
        private readonly LocalActorRegistry $localRegistry,
        private readonly ClusterRefFactory $refFactory,
        private readonly ActorRef $membershipRef,
        private readonly ActorRef $connectionSupervisorRef,
        private readonly RoutingSnapshotHolder $routingSnapshotHolder,
        private readonly MeshTransport $transport,
        private readonly ControlFrameCodec $controlCodec,
        private readonly MessagePayloadCodec $payloadCodec,
        private readonly Runtime $runtime,
        private readonly ActorSystem $system,
        private readonly Tracer $tracer,
        private readonly Meter $meter,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly LivenessThrottle $livenessThrottle,
        private readonly PeerConnectionPool $connectionPool,
        private readonly ?PeerAuthenticator $authenticator = null,
    ) {}

    /**
     * Boot a cluster node from the given topology, wiring all collaborators.
     *
     * Transport auto-selection lives here (not on {@see ClusterTopology}) because it requires
     * runtime/extension introspection — inspecting the live {@see Runtime} and whether ext-swoole is
     * loaded — which is a boot-time concern, not plain configuration. Topology's `tls`/`authSecret`
     * are static value knobs, so they stay declarative withers on the immutable config object.
     *
     * @param TypeRegistry|null $userTypes Optional registry pre-populated with the caller's
     *        user-defined message types (e.g. for cross-node tell/ask). Boot adds the cluster
     *        wire protocol types (Handshake, HandshakeAck, GossipPayload, etc.) into this
     *        same registry so one shared registry covers all serialization needs.
     *        Pass `null` to use a protocol-only registry (sufficient for membership-only setups).
     * @param MeshTransport|null $transport Optional transport override (e.g. LoopbackMeshTransport
     *        with a shared hub for multi-node integration tests). Auto-selects SwooleMeshTransport
     *        when ext-swoole is loaded; falls back to a fresh LoopbackMeshTransport.
     * @param Observability|null $observability Optional telemetry provider. When supplied, wires
     *        real W3C trace-context inject/extract and opens `cluster.send`, `cluster.receive`,
     *        `cluster.ask`, and `cluster.handshake` spans. Defaults to {@see NoopObservability}
     *        (zero overhead when not provided).
     */
    public static function boot(
        ActorSystem $system,
        ClusterTopology $topology,
        ?TypeRegistry $userTypes = null,
        ?MeshTransport $transport = null,
        ?Observability $observability = null,
        ?LoggerInterface $logger = null,
    ): self {
        $observability ??= new NoopObservability();
        $logger ??= new NullLogger();
        $runtime = $system->runtime();

        // 1. Cluster frame serializer — shared registry for cluster wire types + user message types.
        $frameSerializer = self::buildSerializer($userTypes);

        // 2. Connection-state collaborators: the endpoint registry, liveness throttle, and routing
        //    snapshot holder are all owned by ConnectionSupervisor going forward, but constructed
        //    here (rather than inside ClusterNode's own constructor) because the supervisor is
        //    spawned before the node itself and needs the registry + holder directly, while the
        //    throttle is shared between the node (observeLiveness reads) and the supervisor
        //    (forget-on-close writes, via an injected closure below).
        $endpointRegistry = new MutableEndpointRegistry();
        $livenessThrottle = new LivenessThrottle();
        $routingSnapshotHolder = new RoutingSnapshotHolder();

        // Handshake authentication: enforced only when the topology carries a shared secret. Built
        // early (rather than alongside the other membership collaborators below) because the
        // connection pool's handshake preamble and the supervisor's verified-prefix policy both
        // need it before either is constructed.
        $authenticator = $topology->authSecret !== null
            ? new HandshakeAuthenticator($topology->authSecret, clock: $system->clock())
            : null;

        // 3. Message delivery collaborators.
        $localRegistry = new LocalActorRegistry();
        $localDelivery = new LocalDelivery($localRegistry, $observability);
        $meter = $observability->meter();
        $askRegistry = new TcpAskRegistry($runtime, meter: $meter);

        // 4. User-message codec. When $userTypes is non-null it shares the registry with the
        //    frame serializer so user types are reachable on both encode and decode paths.
        //    Falls back to a separate empty TypeRegistry when null (membership-only setups).
        $codec = new ClusterMessageCodec($frameSerializer, $userTypes ?? new TypeRegistry());
        $payloadCodec = new MessagePayloadCodec();
        $controlCodec = new ControlFrameCodec();

        // 5. Transport (override or auto-select). The auto-selected Swoole transport gets a
        //    handler-error reporter so a throwing frame handler is counted/logged, not silently dropped.
        $meshTransport = $transport ?? self::selectTransport(
            $runtime,
            $topology,
            self::buildHandlerErrorReporter($meter, $logger),
        );

        // Warn on a silent loopback fallback with real seeds: an auto-selected in-process
        // LoopbackMeshTransport cannot reach a remote seed, so the node would boot "successfully"
        // as an isolated island and never converge — the classic silent-degradation trap. This
        // only fires on auto-selection (no explicit $transport, which is how loopback tests opt in).
        if ($transport === null
            && $meshTransport instanceof LoopbackMeshTransport
            && !$topology->singleNode
            && $topology->seeds !== []) {
            $logger->warning('cluster.transport.loopback_fallback', [
                'detail' => 'ext-swoole/SwooleRuntime not detected; using in-process LoopbackMeshTransport. '
                    . 'This node cannot reach its TCP seeds and will not join a real cluster. '
                    . 'Run on SwooleRuntime with ext-swoole, or pass an explicit transport.',
                'seeds' => count($topology->seeds),
            ]);
        }

        // 6. Outbound connection pool. Injected (rather than self-constructed on ClusterNode) so its
        //    evict() can also be handed to ConnectionSupervisor for the Leave-only EvictPeer path.
        $connectionPool = new PeerConnectionPool(
            $meshTransport,
            $runtime,
            $topology->reconnectInitialBackoff,
            $topology->reconnectMaxBackoff,
            self::handshakePreamble($topology, $authenticator, $controlCodec),
            $logger,
        );

        // 7. Lazy sender closure: resolved after the node is constructed.
        //    The closure is never invoked before $system->run(); by then $selfNode is non-null.
        /** @var ClusterNode|null $selfNode */
        $selfNode = null;

        /**
         *                 across the closure boundary; the variable is always set before first call.
         */
        $sender = static function (string $prefix, Frame $frame) use (&$selfNode): DeliveryOutcome {
            if ($selfNode !== null) {
                return $selfNode->sendByPrefix($prefix, $frame);
            }

            return DeliveryOutcome::Dropped;
        };

        // 8. Outbound sink for user messages (ClusterRef::tell / ask). The MessagePayload
        //    envelope is the per-message hot path, so it uses the hand-rolled codec rather
        //    than the generic Valinor-backed serializer (which stays on handshake/gossip).
        $outboundSink = self::buildOutboundSink($sender, $payloadCodec, $meter);

        // 9. Inbox router + ref factory — wire real or noop trace seams from $observability.
        $traceInjector = $observability->isEnabled()
            ? new ObservabilityTraceContextInjector($observability)
            : new NoopTraceContextInjector();

        $traceExtractor = $observability->isEnabled()
            ? new ObservabilityTraceContextExtractor($observability)
            : new NoopTraceContextExtractor();

        $tracer = $observability->tracer();

        $inboxRouter = new InboxRouter(
            $localDelivery,
            $askRegistry,
            $codec,
            $outboundSink,
            $traceExtractor,
            $traceInjector,
            tracer: $tracer,
            meter: $meter,
        );

        // Departed-peer tracker: an actor-confined set of Down peers that backs ClusterRef::isAlive()
        // without any blocking probe. It decorates the event publisher (added on NodeDown, removed on
        // NodeUp) and exposes an isAlive(NodeAddress) closure the ref factory binds per target.
        $departedTracker = new DepartedPeerTracker(
            new EventDispatcherMembershipEventPublisher($system->eventDispatcher()),
        );

        $refFactory = new ClusterRefFactory(
            $topology->self,
            $outboundSink,
            $localDelivery,
            $askRegistry,
            $codec,
            $traceInjector,
            $tracer,
            $meter,
            $departedTracker->isAlive(...),
        );

        // 10. Membership collaborators.
        $effectInterpreter = new TcpMembershipEffectInterpreter($controlCodec, $sender, $meter);
        $eventPublisher = new AskFailingMembershipEventPublisher($departedTracker, $askRegistry);

        $service = new MembershipService($topology, $topology->maxNoHeartbeat);
        $detector = new PhiAccrualDetector(
            $topology->phiSampleSize,
            (float) $topology->phiMinStdDev->toMillis(),
        );

        $membershipActor = new MembershipActor(
            service: $service,
            detector: $detector,
            // Shuffled-cycle (not uniform-random) selection: bounds per-peer gossip
            // inter-arrival deterministically so data-idle links cannot fall silent
            // past the failure detector's thresholds (see ShuffledCycleSelector).
            selector: new ShuffledCycleSelector(),
            effectInterpreter: $effectInterpreter,
            eventPublisher: $eventPublisher,
            clock: $system->clock(),
            heartbeatInterval: $topology->heartbeatInterval,
            gossipInterval: $topology->gossipInterval,
            logger: $logger,
            meter: $meter,
        );

        $nodeSlug = (string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $topology->self->node);
        $membershipRef = $system->spawn($membershipActor->props(), 'cluster-membership-' . $nodeSlug);

        // 11. Connection supervisor — owns the routing state (accepted links, tombstones, verified
        //     prefixes, the endpoint registry) behind the RoutingSnapshot published to
        //     $routingSnapshotHolder. Spawned once membershipRef exists, since it tells membership
        //     directly (HandshakeReceived, PeerLinkClosed) from within its own message handlers.
        //     Suffixed with $nodeSlug (like the membership actor) so multiple simulated nodes can
        //     share one ActorSystem, as several integration tests do.
        $connectionSupervisor = new ConnectionSupervisor(
            endpointRegistry: $endpointRegistry,
            snapshotHolder: $routingSnapshotHolder,
            membershipRef: $membershipRef,
            dispatcher: $system->eventDispatcher(),
            askRegistry: $askRegistry,
            forgetThrottle: $livenessThrottle->forget(...),
            evictFromPool: $connectionPool->evict(...),
            authenticationEnabled: $authenticator !== null,
            meter: $meter,
            logger: $logger,
        );
        $connectionSupervisorRef = $system->spawn($connectionSupervisor->props(), 'cluster-connections-' . $nodeSlug);

        // 12. Construct the node — the lazy $sender can now be resolved via $selfNode.
        $selfNode = new self(
            selfAddress: $topology->self,
            topology: $topology,
            localRegistry: $localRegistry,
            refFactory: $refFactory,
            membershipRef: $membershipRef,
            connectionSupervisorRef: $connectionSupervisorRef,
            routingSnapshotHolder: $routingSnapshotHolder,
            transport: $meshTransport,
            controlCodec: $controlCodec,
            payloadCodec: $payloadCodec,
            runtime: $runtime,
            system: $system,
            tracer: $tracer,
            meter: $meter,
            dispatcher: $system->eventDispatcher(),
            logger: $logger,
            livenessThrottle: $livenessThrottle,
            connectionPool: $connectionPool,
            authenticator: $authenticator,
        );

        // 13. Start serving: build the inbound accept pump once, then wire every accepted link
        //     through it.
        $inboundAcceptor = $selfNode->wireInboundLink($inboxRouter);
        $meshTransport->serve(
            $topology->bindEndpoint,
            static function (PeerLink $link) use ($inboundAcceptor): void {
                $inboundAcceptor->accept($link);
            },
        );

        // 14. Dial seeds.
        foreach ($topology->seeds as $seedEndpoint) {
            $selfNode->dialSeed($seedEndpoint, $inboxRouter);
        }

        return $selfNode;
    }

    /**
     * Expose a local actor for delivery from remote peers.
     *
     * @param ActorRef<object> $ref
     */
    public function expose(ActorRef $ref): void
    {
        $this->localRegistry->expose($ref);
    }

    /**
     * Return a ClusterRef that routes messages to the actor at `$path` on `$node`.
     *
     * @return ClusterRef<object>
     */
    public function refFor(NodeAddress $node, ActorPath $path): ClusterRef
    {
        return $this->refFactory->refFor($node, $path);
    }

    /**
     * Asynchronously query the current cluster view.
     *
     * Sends a {@see GetClusterView} message to the membership actor; the view will be
     * delivered to `$replyTo` on the next event-loop tick. Use this from timer callbacks
     * (where {@see view()} cannot yield) by pre-spawning a collector actor.
     *
     * @param ActorRef<ClusterView> $replyTo
     */
    public function queryViewAsync(ActorRef $replyTo): void
    {
        $this->membershipRef->tell(new GetClusterView($replyTo));
    }

    /**
     * Return this node's own NodeAddress.
     */
    public function self(): NodeAddress
    {
        return $this->selfAddress;
    }

    /**
     * Query the current cluster view from the membership actor.
     * Must be called from within the runtime event loop (inside a scheduleOnce callback).
     * Returns ClusterView::empty() when the actor has not yet replied within two yields.
     */
    public function view(): ClusterView
    {
        /** @var ClusterView|null $captured */
        $captured = null;

        $viewBehavior = Behavior::receive(
            /**
             * @param ActorContext<object> $_ctx
             * @return Behavior<object>
             */
            static function (ActorContext $_ctx, object $msg) use (&$captured): Behavior {
                if ($msg instanceof ClusterView) {
                    $captured = $msg;
                }

                return Behavior::stopped();
            },
        );

        /** @var ActorRef<ClusterView> $replyRef */
        $replyRef = $this->system->spawnAnonymous(Props::fromBehavior($viewBehavior));
        $this->membershipRef->tell(new GetClusterView($replyRef));

        // Yield twice to let the membership actor process the message and the reply
        // actor receive it. Works under FiberRuntime and SwooleRuntime.
        $this->runtime->yield();
        $this->runtime->yield();

        return $captured ?? ClusterView::empty();
    }

    /**
     * Broadcast Leave to all connected peers, stop the membership gossip loop, close all
     * connections, and close the transport. Call before or during ActorSystem shutdown to
     * signal graceful departure.
     *
     * The gossip loop is stopped (via a PoisonPill to the membership actor, which cancels its
     * heartbeat/gossip ticks on PostStop) and {@see $stopped} is set so no further frame can be
     * emitted. Without this a departed node kept gossiping, and — because every connection now
     * re-announces its identity via the handshake preamble — a post-Leave re-dial would re-join
     * the very mesh this node just left.
     */
    public function shutdown(): void
    {
        $this->stopped = true;
        $this->system->stop($this->membershipRef);
        // Also stop the supervisor so a same-identity reboot (restart-rejoin) can spawn a fresh one
        // under the same 'cluster-connections-<nodeSlug>' name — ActorSystem::spawn() only prunes
        // and replaces a DEAD child of that name, and throws if a live one still exists.
        $this->system->stop($this->connectionSupervisorRef);

        $leavePayload = new LeavePayload($this->selfAddress->toPathPrefix());
        // Self-attesting Leave (SEC-008 check 1): sign with our own identity so a peer that
        // verifies it knows THIS node produced the notice, not merely that it arrived over an
        // authenticated link. No-op (unsigned frame) when the cluster runs without a secret.
        $leavePayload = $this->authenticator?->signLeave($leavePayload) ?? $leavePayload;
        $leaveBytes = $this->controlCodec->packLeave($leavePayload);
        $leaveFrame = new Frame(FrameType::Leave, $leaveBytes);

        // Accepted-link directory now lives on ConnectionSupervisor; read the current snapshot for
        // the broadcast (today's direct-send semantics are unchanged — only the map's owner moved).
        foreach ($this->routingSnapshotHolder->current()->acceptedLinks as $link) {
            $link->sendFrame($leaveFrame);
            $link->close();
        }

        $this->connectionPool->each(static function (PeerConnection $conn) use ($leaveFrame): void {
            $conn->sendFrame($leaveFrame);
        });

        $this->connectionPool->closeAll();

        $this->transport->close();
    }

    // -------------------------------------------------------------------------
    // Internal routing
    // -------------------------------------------------------------------------

    /**
     * Route a frame to the peer identified by NodeAddress path-prefix. Prefers an
     * outbound PeerConnection (lazily created from the endpoint registry) so that
     * frames arrive at the peer's wireInboundLink handler, which has ingress set up
     * after the initial handshake. Falls back to the accepted inbound link only when
     * the endpoint is not yet known (e.g. very early in the handshake sequence).
     * Called by the lazy $sender closure in boot().
     *
     * Both maps are read from the same {@see RoutingSnapshot} snapshot instance so the fallback
     * check sees a consistent view; either read may lag ConnectionSupervisor's own state by
     * however long its mailbox takes to drain (see {@see RoutingSnapshot}'s docblock). A frame
     * arriving before the snapshot catches up simply takes the Dropped path below — exactly the
     * outcome a pre-handshake send already had, and consistent with cluster delivery's
     * at-most-once semantics.
     *
     * @internal Used by the $sender closure injected into TcpMembershipEffectInterpreter.
     */
    public function sendByPrefix(string $prefix, Frame $frame): DeliveryOutcome
    {
        if ($this->stopped) {
            // Departed node: emit nothing further (see $stopped) — no gossip, ack, or re-dial.
            return DeliveryOutcome::Dropped;
        }

        $snapshot = $this->routingSnapshotHolder->current();
        $endpoint = $snapshot->endpoints[$prefix] ?? null;

        if ($endpoint !== null) {
            $conn = $this->connectionPool->dial($endpoint);

            return $this->routeSend($frame, static fn(): DeliveryOutcome => $conn->sendFrame($frame));
        }

        // Endpoint not yet in registry — fall back to the accepted inbound link.
        if (isset($snapshot->acceptedLinks[$prefix])) {
            $link = $snapshot->acceptedLinks[$prefix];

            return $this->routeSend($frame, static fn(): DeliveryOutcome => $link->sendFrame($frame));
        }

        // No route: neither a resolvable outbound endpoint nor an accepted inbound link for this
        // prefix. Previously the frame vanished here with no signal; report it so the sink can count it.
        return DeliveryOutcome::Dropped;
    }

    /**
     * @internal Forward-declared seam for a future service-discovery track; not yet callable. It
     *           exists only to reserve the method shape and always throws — do not depend on it.
     *
     * Receptionist pattern for cluster service discovery. Planned for a future release.
     *
     * @throws BadMethodCallException Always.
     */
    public function receptionist(): never
    {
        throw new BadMethodCallException(
            'Receptionist-based cluster service discovery is not implemented yet. '
            . 'Use ClusterNode::refFor(NodeAddress, ActorPath) with a known address until it lands.',
        );
    }

    /**
     * Route an outbound frame to a peer link, choosing synchronous vs. off-loop dispatch by frame class.
     *
     * Control-plane frames (gossip / handshake-ack / leave relay) are emitted by the membership actor's
     * message loop. A stalled peer's ≤5 s socket write on that loop would delay heartbeat processing for
     * healthy peers, and the next failure-detection tick would then measure their silence against an
     * inflated processing-time `now` and falsely suspect them — so control frames are dispatched OFF the
     * loop via {@see dispatchControlSend()}.
     *
     * User {@see FrameType::Message} frames ({@see ClusterRef::tell()} / {@see ClusterRef::ask()} through
     * the OutboundSink) are emitted by application-actor coroutines, NOT the membership loop, so they stay
     * synchronous: this is the per-message hot path where a coroutine spawn per send is pure overhead, and
     * a blocking write here only ever back-pressures the calling actor, never failure detection. Per-link
     * write ordering is preserved either way by {@see SwoolePeerLink}'s write mutex.
     *
     * @param Closure(): DeliveryOutcome $send
     */
    private function routeSend(Frame $frame, Closure $send): DeliveryOutcome
    {
        if ($frame->type === FrameType::Message) {
            // Message frames send synchronously so the admission outcome is returned to the caller.
            return $send();
        }

        // Control frames dispatch on their own coroutine (see dispatchControlSend); the outcome is
        // not synchronously available, and control-send failures are surfaced via a dedicated
        // counter instead. Report the hand-off as admitted.
        $this->dispatchControlSend($frame, $send);

        return DeliveryOutcome::Admitted;
    }

    /**
     * Dispatch a control-plane frame send on its own runtime coroutine so a peer whose socket write
     * stalls — bounded by {@see SwoolePeerLink::SEND_TIMEOUT_SECONDS} — cannot block the membership
     * actor's message loop; moving the send off the loop removes the head-of-line coupling that the
     * send timeout alone only bounds. Per-link write ordering is still preserved by
     * {@see SwoolePeerLink}'s write mutex (a capacity-1 channel served FIFO). Control frames are
     * fire-and-forget and idempotent — gossip is a last-writer-wins merge and a handshake-ack is
     * one-shot — so async dispatch changes no membership semantics.
     *
     * A throwing send is isolated so it can never escape the one-shot coroutine, but — unlike a
     * connection fault, which the link's own short-write teardown + reconnect already recovers — a
     * serialization or logic fault would otherwise fail silently on every attempt. So it is recorded
     * ({@see recordControlSendFailure()}) rather than swallowed blind.
     *
     * @param Closure(): DeliveryOutcome $send
     */
    private function dispatchControlSend(Frame $frame, Closure $send): void
    {
        $this->runtime->spawn(function () use ($frame, $send): void {
            try {
                // Control frame: fire-and-forget on this coroutine. The admission outcome is not
                // propagated (the caller already returned); a failed send is surfaced by the catch.
                $send();
            } catch (Throwable $e) {
                $this->recordControlSendFailure($frame->type, $e);
            }
        });
    }

    /**
     * Make an otherwise-silent control-plane send failure observable — the same "silent drop → surface
     * it" discipline {@see recordDecodeFailure()} applies on the inbound side.
     */
    private function recordControlSendFailure(FrameType $type, Throwable $e): void
    {
        try {
            $this->controlSendFailed ??= $this->meter->counter(
                'nexus.cluster.control_send.failed',
                '{send}',
                'Control-plane frame sends (gossip / handshake-ack / leave) that failed on their coroutine',
            );
            $this->controlSendFailed->add(1, ['frame.type' => $type->name]);
            $this->logger->debug(
                'cluster.control_send.failed',
                ['error' => $e->getMessage(), 'frame.type' => $type->name],
            );
        } catch (Throwable) {
            // Telemetry must never break cluster operations.
        }
    }

    // -------------------------------------------------------------------------
    // Frame pump wiring
    // -------------------------------------------------------------------------


    /**
     * Forward a liveness observation for `$peerAddr` to the membership actor, coalesced
     * to at most one per peer per detector sample interval. Every inbound frame proves
     * the peer alive, but per-frame observations flood the membership mailbox under
     * load and carry no extra detection value (the phi detector discards sub-interval
     * samples) — see {@see LivenessThrottle}.
     */
    private function observeLiveness(NodeAddress $peerAddr): void
    {
        if ($this->livenessThrottle->shouldObserve($peerAddr->toPathPrefix(), hrtime(true))) {
            $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null, $this->system->clock()->now()));
        }
    }

    /**
     * Shared per-link frame state machine, driven by BOTH the accepted-inbound and the dialed-outbound
     * frame pumps. Handling the Handshake → identification → (ack / gossip / leave / message) sequence
     * in one place is what keeps the two paths from drifting on which guards they apply (they had
     * already diverged).
     *
     * On a successful handshake, {@see LinkState::$link} distinguishes the two callers: non-null on
     * the accepted-inbound path (set by {@see InboundLinkAcceptor::accept()} before any frame is
     * processed), null on the dialed-outbound path (which has no accepted-link bookkeeping to do).
     * Forwarding it as-is on {@see \Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterIdentifiedLink}
     * lets {@see \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor} apply the C10
     * accepted-link-slot write only when there is one, with no separate per-path closure needed here.
     *
     * SEC-008 check 5 (audited): every branch that can reject a frame — the Handshake branch on
     * parse/auth failure or {@see isReidentifyMismatch()}, and the Leave branch via
     * {@see admitSelfAttestingLeave()} — `return`s before any {@see observeLiveness()} call. Only
     * Gossip and Message branches feed liveness, and only after frame-level processing succeeds;
     * a per-entry skip inside {@see applyHandshakeAckView()} / {@see registerGossipEndpoint()}
     * does not withhold liveness for the frame as a whole (the sender's link is still genuinely
     * live — only that one entry's registration is untrusted).
     */
    private function handleLinkFrame(
        Frame $frame,
        LinkState $state,
        InboxRouter $inboxRouter,
        string $remoteLabel,
    ): void {
        if ($frame->type === FrameType::Handshake) {
            $span = $this->safeStartHandshakeSpan();
            $parsed = $this->parseHandshakeFrame($frame);

            if ($parsed !== null) {
                [$peerAddr, $peerEndpoint, $handshake] = $parsed;

                if ($this->isReidentifyMismatch($state, $peerAddr)) {
                    $this->recordControlRejected(self::CHECK_REIDENTIFY_MISMATCH);
                    $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'rejected');
                    $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.reidentify_rejected', [
                        'claimed_peer' => $peerAddr->toPathPrefix(),
                        'existing_peer' => $state->peerAddr?->toPathPrefix(),
                        'peer_endpoint' => $remoteLabel,
                    ]));
                    $this->safeEndSpan($span);

                    return;
                }

                // Side effects only AFTER the re-identify check: registry write, verified-set mark,
                // tombstone clear, C10 accepted-link supersede, and the HandshakeReceived tell to
                // membership all happen inside ConnectionSupervisor's own serialized mailbox, in
                // that order — the load-bearing ordering invariant (registration precedes membership
                // processing the handshake it produced) rides the actor hop rather than this
                // synchronous call stack.
                $state->peerAddr = $peerAddr;
                $state->boundAdvertise = $handshake->advertise;
                $state->ingress = new FrameIngress($inboxRouter, $peerAddr, $this->payloadCodec, meter: $this->meter);
                // Stamp ingress time here (frame-parse), not at actor-processing time, so the phi
                // detector is fed the arrival instant regardless of membership-mailbox latency.
                $observedAt = $this->system->clock()->now();
                $this->connectionSupervisorRef->tell(new RegisterIdentifiedLink(
                    $peerAddr,
                    $peerEndpoint,
                    $handshake->advertise,
                    $state->link,
                    $handshake,
                    $observedAt,
                ));
                $this->safeSpanAttribute($span, 'nexus.cluster.peer', $peerAddr->toPathPrefix());
                $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'accepted');
                $this->safeDispatch(new PeerConnected($peerAddr, $peerEndpoint));
            } else {
                $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'rejected');
                $this->safeRecordRejection();
                $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.rejected', [
                    'peer_endpoint' => $remoteLabel,
                    'reason' => 'parse_failure',
                ]));
            }

            $this->safeEndSpan($span);

            return;
        }

        $peerAddr = $state->peerAddr;

        if ($peerAddr === null) {
            return; // Not yet identified; ignore frames until Handshake arrives.
        }

        // Only process a HandshakeAck AFTER the link has been identified by a verified Handshake.
        // Processed earlier, an unauthenticated peer could inject an ack whose view map overwrites
        // endpoint-registry entries (redirecting a victim prefix's gossip and user messages to an
        // attacker) before any HMAC check runs. The ack always follows the peer's own Handshake on
        // the same link, so this gate is safe.
        if ($frame->type === FrameType::HandshakeAck) {
            $this->applyHandshakeAckView($frame, $state);

            return;
        }

        if ($frame->type === FrameType::Gossip) {
            $this->processGossipFrame($frame, $peerAddr, $state);
            // Gossip is the steady-state heartbeat: receiving it proves the peer is alive, so it MUST
            // feed the failure detector. Without this the phi detector starves once traffic goes quiet
            // and falsely suspects an idle-but-alive peer (there is no separate Ping/Pong heartbeat).
            $this->observeLiveness($peerAddr);

            return;
        }

        if ($frame->type === FrameType::Leave) {
            $this->processLeaveFrame($frame, $peerAddr);

            return;
        }

        if ($frame->type === FrameType::Message && $state->ingress !== null) {
            $state->ingress->ingest($frame);
            $this->observeLiveness($peerAddr);

            return;
        }

        $this->observeLiveness($peerAddr);
    }

    /**
     * Build the frame-pump wiring for every accepted inbound PeerLink and hand it to a fresh
     * {@see InboundLinkAcceptor}, called once at boot. The acceptor owns the per-link concurrency
     * cap, Slowloris deadline, and frame/close pump; what stays here is the two closures it drives:
     *
     *  - `$frameSink` routes each frame through the shared {@see handleLinkFrame()} state machine,
     *    which now does its own C10 slot-registration bookkeeping via the
     *    {@see \Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterIdentifiedLink} tell (using
     *    `LinkState::$link`, set synchronously by {@see InboundLinkAcceptor::accept()} before this
     *    link's frame pump is ever wired) — no separate accepted-callback needed here anymore.
     *  - `$onLinkClosed` tells {@see \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor}
     *    the close: the identity-guarded accepted-link removal, tombstone, liveness-throttle forget,
     *    membership notification, and ask-registry failure all now happen in its own mailbox.
     */
    private function wireInboundLink(InboxRouter $inboxRouter): InboundLinkAcceptor
    {
        $frameSink = function (Frame $frame, LinkState $state, string $remoteLabel) use ($inboxRouter): void {
            $this->handleLinkFrame($frame, $state, $inboxRouter, $remoteLabel);
        };

        $onLinkClosed = function (LinkState $state, PeerLink $link): void {
            $peerAddr = $state->peerAddr;

            if ($peerAddr === null) {
                return;
            }

            $this->connectionSupervisorRef->tell(new LinkClosed($peerAddr, $link));
        };

        return new InboundLinkAcceptor(
            $this->runtime,
            $this->topology->maxInboundLinks,
            $this->topology->handshakeTimeout,
            $frameSink,
            $onLinkClosed,
            $this->logger,
        );
    }

    /**
     * Dial a seed endpoint: create an outbound PeerConnection (via the shared
     * {@see PeerConnectionPool}) and wire its frame pump to the shared {@see handleLinkFrame()}
     * state machine. The self-Handshake is sent by the PeerConnection preamble on the initial
     * connect and on every reconnect (see {@see handshakePreamble()}), so a dropped seed link
     * re-identifies us on reconnect instead of the seed silently dropping our post-reconnect frames.
     */
    private function dialSeed(NodeEndpoint $seedEndpoint, InboxRouter $inboxRouter): void
    {
        if ($this->connectionPool->existing($seedEndpoint) !== null) {
            return; // Already dialed (e.g. shared hub in test multi-boot scenario).
        }

        $conn = $this->connectionPool->dial($seedEndpoint);

        $state = new LinkState();
        $remoteLabel = (string) $seedEndpoint;

        $conn->onFrame(function (Frame $frame) use ($state, $inboxRouter, $remoteLabel): void {
            // Outbound connection: identity is tracked in $state ($state->link stays null, so
            // RegisterIdentifiedLink's accepted-link write is skipped for this path — see handleLinkFrame()).
            $this->handleLinkFrame($frame, $state, $inboxRouter, $remoteLabel);
        });
    }

    // -------------------------------------------------------------------------
    // Frame parsing helpers
    // -------------------------------------------------------------------------

    /**
     * SEC-008 check 2 (re-identification pinning): whether a freshly-parsed Handshake identity
     * conflicts with the identity this link already bound. Applied unconditionally — independent
     * of whether a cluster secret is configured — because it guards link-slot integrity, not
     * signature validity: a link already identified as one peer must never be silently rebound to
     * a different one. A same-prefix re-handshake (the peer re-announcing itself, e.g. on
     * endpoint failover) is NOT a mismatch and proceeds unchanged (C10 supersede semantics).
     */
    private function isReidentifyMismatch(LinkState $state, NodeAddress $parsedPeerAddr): bool
    {
        return $state->peerAddr !== null && $state->peerAddr->toPathPrefix() !== $parsedPeerAddr->toPathPrefix();
    }

    /**
     * Parse and validate a Handshake frame payload, returning the parsed address, endpoint, and
     * Handshake as a tuple — or null on any validation failure.
     *
     * PURE with respect to routing state: no registry write, no verified-set marking, no
     * tombstone clear happens here — {@see \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor}
     * applies those via the {@see \Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterIdentifiedLink}
     * tell, which {@see handleLinkFrame()} sends only after the re-identification check passes, so a
     * rejected impersonation attempt cannot poison the claimed identity's routing state
     * (SEC-008 review fix). The one deliberate exception: a successful HMAC verify() consumes
     * the frame's nonce in the replay guard, so even a subsequently-rejected frame's signature
     * cannot be replayed at this node.
     *
     * @return array{NodeAddress, NodeEndpoint, Handshake}|null
     */
    private function parseHandshakeFrame(Frame $frame): ?array
    {
        try {
            $obj = $this->controlCodec->unpackHandshake($frame->payload);
        } catch (Throwable) {
            $this->recordDecodeFailure('handshake');

            return null;
        }

        // Gate the DATA path here, synchronously, before any ingress is wired: a peer whose
        // cluster name or protocol version does not match ours must never have its Message
        // frames routed to local actors. The membership actor performs the same check when it
        // decides admission, but that runs asynchronously and only governs the membership view —
        // it does not stop frame ingress. Rejecting at parse time closes that gap.
        if ($obj->clusterName !== $this->topology->clusterName
            || $obj->protocolVersion !== MembershipService::PROTOCOL_VERSION) {
            $this->safely(fn(): mixed => $this->logger->debug('cluster.handshake.mismatch', [
                'expected_cluster' => $this->topology->clusterName,
                'peer_cluster' => $obj->clusterName,
                'peer_protocol' => $obj->protocolVersion,
            ]));

            return null;
        }

        // Authenticate BEFORE any ingress is wired: a peer that cannot prove it holds the
        // shared cluster secret is rejected here, so it never joins the view or delivers a
        // frame. No-op when the cluster runs without a secret.
        if ($this->authenticator !== null && !$this->authenticator->verify($obj, time())) {
            $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.unauthenticated', [
                'peer_advertise' => $obj->advertise,
                'peer_cluster' => $obj->clusterName,
            ]));

            return null;
        }

        // Reject a handshake with an incomplete node identity: a peer omitting (or blanking) any of
        // the four NodeAddress fields must not be admitted under a fabricated `/cluster/unknown/...`
        // identity. Treat it as a malformed handshake, counted via the decode-failure counter.
        $cluster = $obj->node['cluster'] ?? '';
        $datacenter = $obj->node['datacenter'] ?? '';
        $application = $obj->node['application'] ?? '';
        $node = $obj->node['node'] ?? '';

        if ($cluster === '' || $datacenter === '' || $application === '' || $node === '') {
            $this->recordDecodeFailure('handshake');

            return null;
        }

        try {
            $peerAddr = new NodeAddress($cluster, $datacenter, $application, $node);
        } catch (InvalidArgumentException) {
            // A peer whose identity segments carry non-URL-safe characters is rejected as malformed
            // rather than admitted — NodeAddress now enforces a collision-free charset at construction.
            $this->recordDecodeFailure('handshake');

            return null;
        }

        try {
            $peerEndpoint = NodeEndpoint::fromString($obj->advertise);
        } catch (Throwable) {
            return null;
        }

        return [$peerAddr, $peerEndpoint, $obj];
    }

    /**
     * Apply the view snapshot in a HandshakeAck to register endpoints for members
     * we haven't seen yet. Fast-paths endpoint discovery without waiting for gossip.
     *
     * SEC-008 check 3 (ack-view authority): an entry whose prefix is already tombstoned is
     * skipped unconditionally — a departed peer must not be resurrected via a stale/forged ack
     * view, independent of whether a cluster secret is configured. The entry naming the ack
     * SENDER's own prefix must additionally match the endpoint its Handshake HMAC-bound to this
     * link ({@see LinkState::$boundAdvertise}); a mismatch is rejected and counted rather than
     * silently registered, since only that entry is otherwise unauthenticated self-reporting.
     * Every entry then flows through {@see registerUnauthenticatedEndpoint()}, the SAME write
     * policy gossip uses (check 4), so a third-party entry cannot overwrite a handshake-verified
     * endpoint via an ack view either — the two per-entry ingestion paths stay symmetric.
     */
    private function applyHandshakeAckView(Frame $frame, LinkState $state): void
    {
        try {
            $obj = $this->controlCodec->unpackHandshakeAck($frame->payload);
        } catch (Throwable) {
            return;
        }

        if (!$obj->accepted) {
            return;
        }

        $senderPrefix = $state->peerAddr?->toPathPrefix();
        $tombstones = $this->routingSnapshotHolder->current()->tombstones;

        foreach ($obj->view as $prefix => $endpointStr) {
            if (isset($tombstones[$prefix])) {
                continue;
            }

            if ($this->authenticator !== null && $prefix === $senderPrefix && $endpointStr !== $state->boundAdvertise) {
                $this->recordControlRejected(self::CHECK_ACK_VIEW_AUTHORITY);

                continue;
            }

            $this->registerUnauthenticatedEndpoint($prefix, $endpointStr, self::CHECK_ACK_VIEW_AUTHORITY);
        }
    }

    /**
     * Process an inbound Gossip frame: register member endpoints and tell membership actor.
     */
    private function processGossipFrame(Frame $frame, NodeAddress $peerAddr, LinkState $state): void
    {
        try {
            $obj = $this->controlCodec->unpackGossip($frame->payload);
        } catch (Throwable) {
            $this->recordDecodeFailure('gossip');

            return;
        }

        $liveMembers = [];
        $senderPrefix = $peerAddr->toPathPrefix();
        $tombstones = $this->routingSnapshotHolder->current()->tombstones;

        foreach ($obj->members as $member) {
            // Tombstone filter: drop any member we have already processed a graceful Leave for. A peer
            // that has not yet learned of the departure keeps gossiping the node as Up; without this
            // filter that lagging gossip re-teaches (resurrects) a node we already removed, and two
            // peers can bounce it back and forth indefinitely (the classic no-tombstone resurrection).
            // The tombstone is cleared (on ConnectionSupervisor) when the node re-handshakes on
            // rejoin, after which its gossip flows again.
            if (isset($tombstones[$member['address']])) {
                continue;
            }

            $this->registerGossipEndpoint($member, $senderPrefix, $state);

            // View merge / forwarding is unaffected by SEC-008 check 4: a member entry this node
            // declines to REGISTER (endpoint-authority mismatch, or a third-party prefix already
            // handshake-verified) is still merged into the membership view and re-gossiped —
            // only the local endpoint-registry write is restricted.
            $liveMembers[] = $member;
        }

        $payload = $liveMembers === $obj->members
            ? $obj
            : new GossipPayload($liveMembers, $obj->registrations);

        $this->membershipRef->tell(new GossipReceived($peerAddr, $payload));
    }

    /**
     * SEC-008 check 4 (gossip endpoint-write policy): register `$member`'s endpoint unless
     * authority forbids the write. A member entry naming the gossip SENDER's own prefix must
     * match the endpoint its Handshake HMAC-bound to this link — the sender cannot use gossip to
     * redirect its own endpoint. Every entry then flows through
     * {@see registerUnauthenticatedEndpoint()} (shared with the ack-view path, check 3), which
     * refuses to overwrite an endpoint a verified Handshake already established. Both restrictions
     * are no-ops without a cluster secret: `$this->authenticator === null` skips the
     * sender-authority check entirely, and ConnectionSupervisor's verified-prefix set stays
     * permanently empty, so registration is unconditional — current, pre-SEC-008 behaviour.
     *
     * @param array{address: string, endpoint: string, incarnation: int, status: int} $member
     */
    private function registerGossipEndpoint(array $member, string $senderPrefix, LinkState $state): void
    {
        $isSendersOwnEntry = $member['address'] === $senderPrefix;

        if ($this->authenticator !== null && $isSendersOwnEntry && $member['endpoint'] !== $state->boundAdvertise) {
            $this->recordControlRejected(self::CHECK_GOSSIP_ENDPOINT_AUTHORITY);

            return;
        }

        $this->registerUnauthenticatedEndpoint(
            $member['address'],
            $member['endpoint'],
            self::CHECK_GOSSIP_ENDPOINT_AUTHORITY,
        );
    }

    /**
     * Register an endpoint learned from an UNAUTHENTICATED per-entry claim — a HandshakeAck view
     * entry or a gossip member entry — the single shared write policy behind SEC-008 checks 3–4.
     * Parses `$endpointStr` here (malformed entries are skipped; a later frame may carry them
     * well-formed) and delegates the actual write policy — refusing a CONFLICTING claim against a
     * handshake-verified prefix, counted under `$rejectCheck` — to {@see ConnectionSupervisor} via
     * a {@see RegisterUnauthenticatedEndpoint} tell, since the verified-prefix set and the registry
     * are both supervisor-internal now.
     */
    private function registerUnauthenticatedEndpoint(string $prefix, string $endpointStr, string $rejectCheck): void
    {
        try {
            $endpoint = NodeEndpoint::fromString($endpointStr);
        } catch (Throwable) {
            return;
        }

        $this->connectionSupervisorRef->tell(new RegisterUnauthenticatedEndpoint($prefix, $endpoint, $rejectCheck));
    }

    /**
     * Parse a Leave frame payload to identify the actual leaving node, then notify
     * the membership actor and forward the frame to all other accepted peers.
     *
     * Forwarding covers star topologies where the leaving node has no direct TCP
     * connection to every peer: the intermediate node (e.g. A in a B→A←C star)
     * relays the Leave so B and C both learn about each other's departures.
     *
     * sendByPrefix is used for forwarding so frames arrive at the recipient's
     * wireInboundLink handler (which has proper ingress) rather than an outbound
     * PeerConnection handler that may have $ingress = null.
     */

    /**
     * SEC-008 check 1: whether `$payload` is a validly self-attested Leave, recording the specific
     * rejection reason when it is not. Split into two counted outcomes: `leave_unsigned` (no
     * signature fields at all — e.g. a peer on an older protocol, or a bare forgery attempt) vs.
     * `leave_replay` (signature fields present but {@see PeerAuthenticator::verifyLeave()} rejected
     * them — bad mac, stale timestamp, or an exact replay).
     */
    private function admitSelfAttestingLeave(PeerAuthenticator $authenticator, LeavePayload $payload): bool
    {
        if ($payload->nonce === null || $payload->issuedAt === null || $payload->mac === null) {
            $this->recordControlRejected(self::CHECK_LEAVE_UNSIGNED);

            return false;
        }

        if (!$authenticator->verifyLeave($payload, time())) {
            $this->recordControlRejected(self::CHECK_LEAVE_REPLAY);

            return false;
        }

        return true;
    }

    private function processLeaveFrame(Frame $frame, ?NodeAddress $senderAddr): void
    {
        try {
            $payload = $this->controlCodec->unpackLeave($frame->payload);
        } catch (Throwable) {
            $this->recordDecodeFailure('leave');

            return;
        }

        // SEC-008 check 1 (self-attesting Leave): with a cluster secret configured, a Leave must
        // carry the leaving node's own signature — otherwise any admitted member could forge a
        // Leave for an arbitrary victim (mesh-wide partition via the star-relay). The mac is
        // leaver-bound and link-independent, so a Leave relayed verbatim through an intermediate
        // node still verifies. Unsigned Leaves are accepted only when no secret is configured
        // (current behaviour unchanged).
        if ($this->authenticator !== null && !$this->admitSelfAttestingLeave($this->authenticator, $payload)) {
            return;
        }

        $leavingAddr = self::parseNodeAddress($payload->node);

        if ($leavingAddr === null || $leavingAddr->toPathPrefix() === $this->selfAddress->toPathPrefix()) {
            return;
        }

        // Dedup: if we have already processed a Leave for this node, skip re-delivery and relay.
        // Reads the snapshot rather than ConnectionSupervisor's own state, so this check can lag a
        // moment behind a RecordTombstone told concurrently; the supervisor's own idempotent guard
        // (see RecordTombstone's handler) is what actually prevents a double-tombstone in that race.
        $leavingPrefix = $leavingAddr->toPathPrefix();
        $snapshot = $this->routingSnapshotHolder->current();

        if (isset($snapshot->tombstones[$leavingPrefix])) {
            return;
        }

        $this->connectionSupervisorRef->tell(new RecordTombstone($leavingPrefix));

        $this->membershipRef->tell(new LeaveReceived($leavingAddr));

        // A graceful Leave means the peer is definitively gone: evict and close our outbound
        // connection to it so its reconnect loop stops. We deliberately do NOT evict on a
        // phi/timeout NodeDown, which may be a false positive that must be allowed to heal.
        $this->connectionSupervisorRef->tell(new EvictPeer($leavingAddr));

        // Forward to all accepted peers except the leaving node and the frame sender.
        $senderPrefix = $senderAddr?->toPathPrefix();

        foreach (array_keys($snapshot->acceptedLinks) as $prefix) {
            if ($prefix !== $leavingPrefix && $prefix !== $senderPrefix) {
                $this->sendByPrefix($prefix, $frame);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Span helpers (swallow-safe — a broken tracer must never disrupt cluster ops)
    // -------------------------------------------------------------------------

    private function safeStartHandshakeSpan(): Span
    {
        try {
            return $this->tracer->startSpan('cluster.handshake', SpanKind::Internal);
        } catch (Throwable) {
            return new NoopSpan();
        }
    }

    private function safeSpanAttribute(Span $span, string $key, string $value): void
    {
        try {
            $span->setAttribute($key, $value);
        } catch (Throwable) {
        }
    }

    private function safeEndSpan(Span $span): void
    {
        try {
            $span->end();
        } catch (Throwable) {
        }
    }

    private function safeRecordRejection(): void
    {
        try {
            $this->handshakeRejected ??= $this->meter->counter(
                'nexus.cluster.handshake.rejected',
                '{handshake}',
                'Cluster handshakes rejected due to parse failure',
            );
            $this->handshakeRejected->add(1);
        } catch (Throwable) {
            // Telemetry must never break cluster operations.
        }
    }

    /**
     * Make an otherwise-silent structural decode failure observable: a corrupt or version-skewed
     * peer whose handshake/gossip/leave frame fails to deserialize is dropped, and without this an
     * operator sees zero signal while the cluster quietly fails to converge.
     */
    private function recordDecodeFailure(string $frameType): void
    {
        try {
            $this->framesDecodeFailed ??= $this->meter->counter(
                'nexus.cluster.frames.decode_failed',
                '{frame}',
                'Inbound frames dropped because they could not be decoded',
            );
            $this->framesDecodeFailed->add(1, ['frame.type' => $frameType]);
            $this->logger->debug('cluster.frame.decode_failed', ['frame.type' => $frameType]);
        } catch (Throwable) {
            // Telemetry must never break cluster operations.
        }
    }

    /**
     * Make a SEC-008 control-frame authorization rejection observable, labeled by which of the
     * five checks fired: {@see admitSelfAttestingLeave()}, {@see isReidentifyMismatch()},
     * {@see applyHandshakeAckView()}, {@see registerGossipEndpoint()} (spec §4.2). Mirrors
     * {@see recordDecodeFailure()}'s silent-drop-must-be-observable discipline.
     */
    private function recordControlRejected(string $check): void
    {
        try {
            $this->controlRejected ??= $this->meter->counter(
                'nexus.cluster.control.rejected',
                '{frame}',
                'Control frames rejected by SEC-008 authorization checks',
            );
            $this->controlRejected->add(1, ['check' => $check]);
            $this->logger->debug('cluster.control.rejected', ['check' => $check]);
        } catch (Throwable) {
            // Telemetry must never break cluster operations.
        }
    }

    private function safeDispatch(object $event): void
    {
        try {
            $this->dispatcher->dispatch($event);
        } catch (Throwable) {
            // Event dispatch must never break cluster operations.
        }
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break cluster operations.
        }
    }

    // -------------------------------------------------------------------------
    // Static factory helpers
    // -------------------------------------------------------------------------

    /**
     * Build a Handshake payload announcing `$topology`'s identity and advertise endpoint. Static
     * (rather than reading `$this->topology`/`$this->authenticator`) because {@see boot()} needs it
     * to build the {@see PeerConnectionPool} preamble BEFORE the node itself is constructed — the
     * pool is now injected (shared with {@see ConnectionSupervisor}'s evict closure) instead of
     * self-constructed.
     */
    private static function buildSelfHandshake(ClusterTopology $topology, ?PeerAuthenticator $authenticator): Handshake
    {
        $handshake = Handshake::forSelf($topology);

        return $authenticator?->sign($handshake) ?? $handshake;
    }

    /**
     * The introduction frame every outbound {@see PeerConnection} sends first — on the initial
     * connect and on every reconnect — so the peer can (re-)identify this node. Making the
     * handshake a per-connection preamble (rather than a once-per-process send) is what lets the
     * mesh survive dropped links and peer restarts: the reconnecting side always re-announces
     * itself, so the remote's inbound handler re-identifies it instead of dropping its frames.
     *
     * The closure re-serialises a freshly built {@see Handshake} on each call so an
     * authenticator's per-handshake nonce/timestamp is regenerated for every connect.
     *
     * @return Closure(): Frame
     */
    private static function handshakePreamble(
        ClusterTopology $topology,
        ?PeerAuthenticator $authenticator,
        ControlFrameCodec $controlCodec,
    ): Closure {
        return static fn(): Frame => new Frame(
            FrameType::Handshake,
            $controlCodec->packHandshake(self::buildSelfHandshake($topology, $authenticator)),
        );
    }

    /**
     * Build the serializer for USER message bodies — arbitrary application types sent via
     * {@see ClusterRef}. The cluster's OWN control frames (Handshake / HandshakeAck / Gossip / Leave)
     * do NOT use this: they go through the hand-rolled {@see ControlFrameCodec} so the reflection-driven
     * Valinor mapper stays off the hot gossip/heartbeat path. Valinor is retained here because user
     * message types are arbitrary and messenger-like, where its mapping is the right tool.
     *
     * `tolerateUnknownKeys` gives user payloads additive forward-compatibility (an older node ignores
     * a field a newer message version added). Type resolution still goes through the {@see TypeRegistry}
     * allowlist, so only registered classes are ever instantiated.
     */
    private static function buildSerializer(?TypeRegistry $userTypes): MessageSerializer
    {
        return new MessagePackMessageSerializer($userTypes ?? new TypeRegistry(), tolerateUnknownKeys: true);
    }

    /**
     * Auto-select the transport based on available extensions and runtime type.
     * Override via the $transport parameter in boot() for tests.
     *
     * @param (Closure(Throwable): void)|null $onHandlerError
     */
    private static function selectTransport(
        Runtime $runtime,
        ClusterTopology $topology,
        ?Closure $onHandlerError = null,
    ): MeshTransport {
        if (extension_loaded('swoole') && $runtime instanceof SwooleRuntime) {
            return new SwooleMeshTransport($runtime, $topology->tls, $topology->maxFrameSize, $onHandlerError);
        }

        return new LoopbackMeshTransport(new LoopbackHub(), $runtime);
    }

    /**
     * Build the reporter passed to the transport so a frame handler that throws in the receive
     * loop is counted + logged instead of silently dropped (the loop is kept alive regardless).
     * Without this a message rejected by a full mailbox, or a downstream codec edge, vanishes with
     * zero operator signal while the sending peer believes delivery succeeded.
     *
     * @return Closure(Throwable): void
     */
    private static function buildHandlerErrorReporter(Meter $meter, LoggerInterface $logger): Closure
    {
        return static function (Throwable $e) use ($meter, $logger): void {
            try {
                $meter->counter(
                    'nexus.cluster.frames.handler_failed',
                    '{frame}',
                    'Inbound frames whose handler threw and was isolated (frame dropped, link kept alive)',
                )->add(1);
            } catch (Throwable) {
                // Telemetry must never break the receive loop.
            }

            try {
                $logger->warning('cluster.frame.handler_failed', ['error' => $e->getMessage()]);
            } catch (Throwable) {
                // Logging must never break the receive loop.
            }
        };
    }

    /**
     * Build an OutboundSink that routes MessagePayload frames via the shared sender closure.
     * User messages flow over the same connections as membership frames, sharing peer identity.
     * The sender returns a {@see DeliveryOutcome}, so this sink meters admission honestly:
     * nexus.cluster.frames.sent counts ONLY admitted frames, while buffered and dropped frames
     * land on nexus.cluster.frames.buffered / nexus.cluster.frames.dropped. Delivery is
     * at-most-once — an admitted frame is written to the socket, not acknowledged by the peer.
     *
     * @param Closure(string, Frame): DeliveryOutcome $sender
     */
    private static function buildOutboundSink(
        Closure $sender,
        MessagePayloadCodec $payloadCodec,
        Meter $meter,
    ): OutboundSink {
        return new class ($sender, $payloadCodec, $meter) implements OutboundSink {
            private ?Counter $framesSent = null;

            private ?Counter $framesBuffered = null;

            private ?Counter $framesDropped = null;

            private ?Histogram $bytesSent = null;

            /**
             * @param Closure(string, Frame): DeliveryOutcome $sender
             */
            public function __construct(
                private readonly Closure $sender,
                private readonly MessagePayloadCodec $payloadCodec,
                private readonly Meter $meter,
            ) {}

            #[Override]
            public function send(NodeAddress $target, MessagePayload $payload): DeliveryOutcome
            {
                $bytes = $this->payloadCodec->pack($payload);
                $this->safely(fn(): mixed => $this->bytesSentHistogram()->record(strlen($bytes)));
                $outcome = ($this->sender)($target->toPathPrefix(), new Frame(FrameType::Message, $bytes));
                $this->safely(fn(): mixed => $this->recordOutcome($outcome));

                return $outcome;
            }

            private function recordOutcome(DeliveryOutcome $outcome): void
            {
                match ($outcome) {
                    DeliveryOutcome::Admitted => $this->framesSentCounter()->add(1, ['frame.type' => 'message']),
                    DeliveryOutcome::Buffered => $this->framesBufferedCounter()->add(1, ['frame.type' => 'message']),
                    DeliveryOutcome::Dropped => $this->framesDroppedCounter()->add(
                        1,
                        // sendByPrefix conflates no-route and peer-unavailable, so the reason is coarse here;
                        // MeshOutboundSink, which resolves the endpoint itself, labels no_route precisely.
                        ['drop.reason' => 'unrouted', 'frame.type' => 'message'],
                    ),
                };
            }

            /**
             * @param callable(): mixed $fn
             */
            private function safely(callable $fn): void
            {
                try {
                    $fn();
                } catch (Throwable) {
                    // Telemetry must never break transport.
                }
            }

            private function framesSentCounter(): Counter
            {
                return $this->framesSent ??= $this->meter->counter(
                    'nexus.cluster.frames.sent',
                    '{frame}',
                    'Cluster frames admitted to a live link (written to the socket) — not a delivery receipt',
                );
            }

            private function framesBufferedCounter(): Counter
            {
                return $this->framesBuffered ??= $this->meter->counter(
                    'nexus.cluster.frames.buffered',
                    '{frame}',
                    'Cluster frames queued for a reconnecting peer — may still be lost if reconnect fails',
                );
            }

            private function framesDroppedCounter(): Counter
            {
                return $this->framesDropped ??= $this->meter->counter(
                    'nexus.cluster.frames.dropped',
                    '{frame}',
                    'Cluster frames not admitted (no route, buffer full, or write failed) — the message is gone',
                );
            }

            private function bytesSentHistogram(): Histogram
            {
                return $this->bytesSent ??= $this->meter->histogram(
                    'nexus.cluster.bytes.sent',
                    'By',
                    'Bytes sent in outbound cluster frames',
                );
            }
        };
    }

    /**
     * Parse a NodeAddress from a path-prefix string: `/cluster/{cluster}/{dc}/{app}/{node}`.
     * Returns null on malformed input.
     */
    private static function parseNodeAddress(string $pathPrefix): ?NodeAddress
    {
        $parts = array_values(array_filter(explode('/', ltrim($pathPrefix, '/'))));

        if (count($parts) !== 5 || $parts[0] !== 'cluster') {
            return null;
        }

        try {
            return new NodeAddress($parts[1], $parts[2], $parts[3], $parts[4]);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
