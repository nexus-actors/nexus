<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use BadMethodCallException;
use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor;
use Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor;
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
use Monadial\Nexus\Cluster\Tcp\Membership\PeerAuthenticator;
use Monadial\Nexus\Cluster\Tcp\Membership\PhiAccrualDetector;
use Monadial\Nexus\Cluster\Tcp\Membership\ShuffledCycleSelector;
use Monadial\Nexus\Cluster\Tcp\Membership\TcpMembershipEffectInterpreter;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterMessageCodec;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterRef;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterRefFactory;
use Monadial\Nexus\Cluster\Tcp\Messaging\InboxRouter;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalActorRegistry;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalDelivery;
use Monadial\Nexus\Cluster\Tcp\Messaging\NoopTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Messaging\NoopTraceContextInjector;
use Monadial\Nexus\Cluster\Tcp\Messaging\OutboundSink;
use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Cluster\Tcp\Protocol\FrameType;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextInjector;
use Monadial\Nexus\Cluster\Tcp\Transport\InboundLinkAcceptor;
use Monadial\Nexus\Cluster\Tcp\Transport\LinkFrame;
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
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Tracer;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
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

use function count;
use function extension_loaded;
use function hrtime;
use function preg_replace;
use function strlen;

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
    /**
     * Set by {@see shutdown()}. Once this node has broadcast its own Leave it must emit no further
     * frames: with the per-connection handshake preamble, any post-Leave gossip/ack that lazily
     * re-dials a peer would re-announce this node's identity and effectively rejoin the mesh it
     * just left. Gating {@see sendByPrefix()} makes graceful departure final.
     */
    private bool $stopped = false;

    private ?Counter $controlSendFailed = null;

    /**
     * The accepted-inbound-link directory, departed-peer tombstones, SEC-008-verified endpoint
     * prefixes, and the (now supervisor-internal) `MutableEndpointRegistry` all moved to {@see
     * ConnectionSupervisor} — this node only ever tells it writes and reads its published {@see
     * Connection\RoutingSnapshot} via {@see $routingSnapshotHolder}. The SEC-008 checks themselves,
     * the per-link `boundAdvertise`, and the parsing of untrusted wire data all moved to {@see
     * InboundLinkActor} — this node keeps only the egress side (`sendByPrefix`/`routeSend`/
     * `dispatchControlSend`) and boot wiring.
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
        //    throttle is shared between every per-link InboundLinkActor (shouldObserve reads) and
        //    the supervisor (forget-on-close writes, via an injected closure below).
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
     * Build the accepted-inbound frame pump: one {@see InboundLinkAcceptor}, constructed once at
     * boot, spawning a fresh {@see InboundLinkActor} per accepted {@see PeerLink} via the injected
     * `$spawner` closure. The per-link Unidentified→Identified state machine (parse/admission,
     * ack/gossip/leave/message handling, SEC-008 checks) lives entirely on the spawned actor now —
     * see its class docblock. Spawned anonymously (root-level, alongside
     * `cluster-membership-<nodeSlug>`/`cluster-connections-<nodeSlug>`): a per-link actor has no
     * reason to be looked up by name, and a stable name would collide across a same-identity
     * restart-rejoin sharing one `ActorSystem` (a real scenario several integration tests exercise) —
     * `shutdown()` closes every accepted link, but does not synchronously guarantee its actor has
     * fully stopped before a caller might reboot, so a predictable name is not safe to reuse.
     */
    private function wireInboundLink(InboxRouter $inboxRouter): InboundLinkAcceptor
    {
        $spawner =
            /**
             * @param Closure(): void $onIdentified
             */
            function (PeerLink $link, Closure $onIdentified) use ($inboxRouter): ActorRef {
                $actor = new InboundLinkActor(
                    supervisorRef: $this->connectionSupervisorRef,
                    membershipRef: $this->membershipRef,
                    snapshotHolder: $this->routingSnapshotHolder,
                    authenticator: $this->authenticator,
                    topology: $this->topology,
                    payloadCodec: $this->payloadCodec,
                    controlCodec: $this->controlCodec,
                    inboxRouter: $inboxRouter,
                    livenessThrottle: $this->livenessThrottle,
                    egress: $this->sendByPrefix(...),
                    tracer: $this->tracer,
                    meter: $this->meter,
                    dispatcher: $this->dispatcher,
                    logger: $this->logger,
                    link: $link,
                    remoteLabel: $link->remote() !== null ? (string) $link->remote() : 'unknown',
                    handshakeTimeout: $this->topology->handshakeTimeout,
                    onIdentified: $onIdentified,
                );

                return $this->system->spawnAnonymous($actor->props());
            };

        return new InboundLinkAcceptor(
            $this->runtime,
            $this->topology->maxInboundLinks,
            $this->topology->handshakeTimeout,
            $spawner,
            $this->system->clock(),
            $this->logger,
        );
    }

    /**
     * Dial a seed endpoint: create an outbound PeerConnection (via the shared
     * {@see PeerConnectionPool}) and spawn a single {@see InboundLinkActor} for its frame pump — the
     * SAME state machine the accepted-inbound path uses, spawned `$link: null` (no accepted-link
     * bookkeeping — there is no accepted PeerLink on this path) and `handshakeTimeout: null` (this
     * path has never had a Slowloris deadline: it is the seed's own accepted-inbound link, on ITS
     * node, that enforces one). One actor per seed persists for the process lifetime, exactly
     * mirroring the previous per-seed `LinkState` — frames from every reconnect generation of the
     * underlying {@see PeerConnection} land on the SAME actor, since `PeerConnection::onFrame()`'s
     * handler list survives reconnects. There is no `LinkClosedNotice` wiring here (mirrors this
     * path's prior behaviour exactly — an outbound reconnect has always been invisible above the
     * `PeerConnection` layer), and a mailbox-`Dropped` frame is not treated as a flood signal (unlike
     * the pre-auth accepted-inbound path) — this is a connection to a known, already-dialed seed.
     * The self-Handshake is sent by the PeerConnection preamble on the initial connect and on every
     * reconnect (see {@see handshakePreamble()}), so a dropped seed link re-identifies us on
     * reconnect instead of the seed silently dropping our post-reconnect frames.
     */
    private function dialSeed(NodeEndpoint $seedEndpoint, InboxRouter $inboxRouter): void
    {
        if ($this->connectionPool->existing($seedEndpoint) !== null) {
            return; // Already dialed (e.g. shared hub in test multi-boot scenario).
        }

        $conn = $this->connectionPool->dial($seedEndpoint);
        $actor = new InboundLinkActor(
            supervisorRef: $this->connectionSupervisorRef,
            membershipRef: $this->membershipRef,
            snapshotHolder: $this->routingSnapshotHolder,
            authenticator: $this->authenticator,
            topology: $this->topology,
            payloadCodec: $this->payloadCodec,
            controlCodec: $this->controlCodec,
            inboxRouter: $inboxRouter,
            livenessThrottle: $this->livenessThrottle,
            egress: $this->sendByPrefix(...),
            tracer: $this->tracer,
            meter: $this->meter,
            dispatcher: $this->dispatcher,
            logger: $this->logger,
            link: null,
            remoteLabel: (string) $seedEndpoint,
            handshakeTimeout: null,
        );
        $ref = $this->system->spawnAnonymous($actor->props());
        $clock = $this->system->clock();

        $conn->onFrame(function (Frame $frame) use ($ref, $clock, $seedEndpoint): void {
            $message = new LinkFrame($frame, $clock->now(), hrtime(true));

            if (!$ref instanceof BackpressureCapable) {
                $ref->tell($message);

                return;
            }

            // Unlike the pre-auth accepted-inbound path, a Dropped enqueue here does not close the
            // link — this is a connection to a known, already-dialed seed, not an unauthenticated
            // flood risk. Still observable rather than silently discarded.
            if ($ref->offer($message) === EnqueueResult::Dropped) {
                $this->logger->debug('cluster.seed_link.frame_dropped', ['peer_endpoint' => (string) $seedEndpoint]);
            }
        });
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
}
