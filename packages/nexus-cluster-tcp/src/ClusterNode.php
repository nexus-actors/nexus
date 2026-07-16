<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use BadMethodCallException;
use Closure;
use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackHub;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackMeshTransport;
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
use Monadial\Nexus\Cluster\Tcp\Membership\Message\HandshakeReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\LeaveReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLinkClosed;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLivenessObserved;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerConnected;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerDisconnected;
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
use Monadial\Nexus\Cluster\Tcp\Swoole\SwooleMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextInjector;
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
use function array_shift;
use function array_values;
use function count;
use function explode;
use function extension_loaded;
use function hrtime;
use function ltrim;
use function preg_replace;
use function spl_object_id;
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
    /**
     * Hard cap on remembered departed-peer path-prefixes. Leave frames are unauthenticated, so an
     * unbounded tombstone set is a memory-exhaustion vector (a peer can relay Leaves for endless
     * fabricated identities). At capacity the earliest-inserted prefix is evicted (FIFO); the
     * worst case of evicting a still-relevant entry is a single redundant LeaveReceived, not a fault.
     */
    private const int MAX_DEPARTED_TOMBSTONES = 10_000;

    /** @var array<string, PeerLink> Accepted inbound links keyed by NodeAddress::toPathPrefix() */
    private array $acceptedLinks = [];

    /** @var array<int, true> Live accepted inbound links by object id — bounds concurrency (see ClusterTopology::$maxInboundLinks). */
    private array $inboundLinks = [];

    /** @var array<string, PeerConnection> Outbound connections keyed by (string) NodeEndpoint */
    private array $outboundConns = [];

    /**
     * @var array<string, true> Departed-peer tombstones keyed by path-prefix. A peer lands here when it
     *      gracefully leaves (Leave frame) OR when its link definitively closes, and is cleared when it
     *      re-handshakes on rejoin. Two jobs: dedup duplicate Leave delivery on relay-back, and filter
     *      lagging gossip so a downed peer cannot be resurrected before its own rejoin re-adds it.
     */
    private array $departedTombstones = [];

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

    /**
     * Coalesces per-frame liveness signals to at most one PeerLivenessObserved per peer
     * per detector sample interval — see {@see LivenessThrottle} for why unthrottled
     * per-frame liveness is a reliability hazard under load.
     */
    private readonly LivenessThrottle $livenessThrottle;

    private function __construct(
        private readonly NodeAddress $selfAddress,
        private readonly ClusterTopology $topology,
        private readonly LocalActorRegistry $localRegistry,
        private readonly ClusterRefFactory $refFactory,
        private readonly ActorRef $membershipRef,
        private readonly MeshTransport $transport,
        private readonly MutableEndpointRegistry $endpointRegistry,
        private readonly ControlFrameCodec $controlCodec,
        private readonly MessagePayloadCodec $payloadCodec,
        private readonly Runtime $runtime,
        private readonly ActorSystem $system,
        private readonly Tracer $tracer,
        private readonly Meter $meter,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly TcpAskRegistry $askRegistry,
        private readonly LoggerInterface $logger,
        private readonly ?HandshakeAuthenticator $authenticator = null,
    ) {
        $this->livenessThrottle = new LivenessThrottle();
    }

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

        // 2. Endpoint registry — grows at runtime via gossip / handshake.
        $endpointRegistry = new MutableEndpointRegistry();

        // 3. Message delivery collaborators.
        $localRegistry = new LocalActorRegistry();
        $localDelivery = new LocalDelivery($localRegistry, $observability);
        $meter = $observability->meter();
        $askRegistry = new TcpAskRegistry($runtime, meter: $meter);

        // 4. User-message codec. When $userTypes is non-null it shares the registry with the
        //    frame serializer so user types are reachable on both encode and decode paths.
        //    Falls back to a separate empty TypeRegistry when null (membership-only setups).
        $codec = new ClusterMessageCodec($frameSerializer, $userTypes ?? new TypeRegistry());

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

        // 6. Lazy sender closure: resolved after the node is constructed.
        //    The closure is never invoked before $system->run(); by then $selfNode is non-null.
        /** @var ClusterNode|null $selfNode */
        $selfNode = null;

        /**
         *                 across the closure boundary; the variable is always set before first call.
         */
        $sender = static function (string $prefix, Frame $frame) use (&$selfNode): void {
            if ($selfNode !== null) {
                $selfNode->sendByPrefix($prefix, $frame);
            }
        };

        // 7. Outbound sink for user messages (ClusterRef::tell / ask). The MessagePayload
        //    envelope is the per-message hot path, so it uses the hand-rolled codec rather
        //    than the generic Valinor-backed serializer (which stays on handshake/gossip).
        $payloadCodec = new MessagePayloadCodec();
        $controlCodec = new ControlFrameCodec();
        $outboundSink = self::buildOutboundSink($sender, $payloadCodec, $meter);

        // 8. Inbox router + ref factory — wire real or noop trace seams from $observability.
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

        // 9. Membership collaborators.
        // Handshake authentication: enforced only when the topology carries a shared secret.
        $authenticator = $topology->authSecret !== null
            ? new HandshakeAuthenticator($topology->authSecret, clock: $system->clock())
            : null;

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

        // 10. Construct the node — the lazy $sender can now be resolved via $selfNode.
        $selfNode = new self(
            selfAddress: $topology->self,
            topology: $topology,
            localRegistry: $localRegistry,
            refFactory: $refFactory,
            membershipRef: $membershipRef,
            transport: $meshTransport,
            endpointRegistry: $endpointRegistry,
            controlCodec: $controlCodec,
            payloadCodec: $payloadCodec,
            runtime: $runtime,
            system: $system,
            tracer: $tracer,
            meter: $meter,
            dispatcher: $system->eventDispatcher(),
            askRegistry: $askRegistry,
            logger: $logger,
            authenticator: $authenticator,
        );

        // 11. Start serving: wire the inbound accept pump.
        $meshTransport->serve(
            $topology->bindEndpoint,
            static function (PeerLink $link) use ($selfNode, $inboxRouter): void {
                $selfNode->wireInboundLink($link, $inboxRouter);
            },
        );

        // 12. Dial seeds.
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

        $leavePayload = new LeavePayload($this->selfAddress->toPathPrefix());
        $leaveBytes = $this->controlCodec->packLeave($leavePayload);
        $leaveFrame = new Frame(FrameType::Leave, $leaveBytes);

        foreach ($this->acceptedLinks as $link) {
            $link->sendFrame($leaveFrame);
            $link->close();
        }

        foreach ($this->outboundConns as $conn) {
            $conn->sendFrame($leaveFrame);
            $conn->close();
        }

        $this->acceptedLinks = [];
        $this->outboundConns = [];

        $this->transport->close();
    }

    // -------------------------------------------------------------------------
    // Internal routing
    // -------------------------------------------------------------------------

    /**
     * Evict and close the lazily-created outbound {@see PeerConnection} for a peer that has
     * gracefully LEFT the cluster (a Leave frame), so its exponential-backoff reconnect loop
     * stops hammering an endpoint that is definitively gone.
     *
     * Deliberately NOT wired to a phi/timeout {@see NodeDown}: such a Down may be a false positive
     * (load jitter, a transient reactor stall), and closing the outbound connection there would
     * stop us gossiping to a still-live peer and prevent it from ever healing back to Up. A peer
     * Downed by suspicion keeps its outbound connection (bounded reconnect) so it can recover.
     * Accepted inbound links are handled separately by their own onClose.
     *
     * @internal Invoked from {@see processLeaveFrame()} on a graceful Leave.
     */
    public function evictOutbound(NodeAddress $node): void
    {
        $endpoint = $this->endpointRegistry->resolveByPrefix($node->toPathPrefix());

        if ($endpoint === null) {
            return;
        }

        $key = (string) $endpoint;
        $conn = $this->outboundConns[$key] ?? null;

        if ($conn === null) {
            return;
        }

        unset($this->outboundConns[$key]);
        $conn->close();
    }

    /**
     * Route a frame to the peer identified by NodeAddress path-prefix. Prefers an
     * outbound PeerConnection (lazily created from the endpoint registry) so that
     * frames arrive at the peer's wireInboundLink handler, which has ingress set up
     * after the initial handshake. Falls back to the accepted inbound link only when
     * the endpoint is not yet known (e.g. very early in the handshake sequence).
     * Called by the lazy $sender closure in boot().
     *
     * @internal Used by the $sender closure injected into TcpMembershipEffectInterpreter.
     */
    public function sendByPrefix(string $prefix, Frame $frame): void
    {
        if ($this->stopped) {
            return; // Departed node: emit nothing further (see $stopped) — no gossip, ack, or re-dial.
        }

        $endpoint = $this->endpointRegistry->resolveByPrefix($prefix);

        if ($endpoint !== null) {
            $key = (string) $endpoint;

            if (!isset($this->outboundConns[$key])) {
                $this->outboundConns[$key] = new PeerConnection(
                    $endpoint,
                    $this->transport,
                    $this->runtime,
                    $this->topology->reconnectInitialBackoff,
                    $this->topology->reconnectMaxBackoff,
                    logger: $this->logger,
                    preamble: $this->handshakePreamble(),
                );
            }

            $conn = $this->outboundConns[$key];
            $this->routeSend($frame, static function () use ($conn, $frame): void {
                $conn->sendFrame($frame);
            });

            return;
        }

        // Endpoint not yet in registry — fall back to the accepted inbound link.
        if (isset($this->acceptedLinks[$prefix])) {
            $link = $this->acceptedLinks[$prefix];
            $this->routeSend($frame, static function () use ($link, $frame): void {
                $link->sendFrame($frame);
            });
        }
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
     * @param Closure(): void $send
     */
    private function routeSend(Frame $frame, Closure $send): void
    {
        if ($frame->type === FrameType::Message) {
            $send();

            return;
        }

        $this->dispatchControlSend($frame, $send);
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
     * @param Closure(): void $send
     */
    private function dispatchControlSend(Frame $frame, Closure $send): void
    {
        $this->runtime->spawn(function () use ($frame, $send): void {
            try {
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
     * already diverged). `$onHandshakeAccepted` runs the path-specific work on a successful handshake —
     * the inbound path cancels its Slowloris deadline and records the accepted link; the outbound path
     * has nothing extra to do.
     *
     * @param Closure(NodeAddress): void $onHandshakeAccepted
     */
    private function handleLinkFrame(
        Frame $frame,
        LinkState $state,
        InboxRouter $inboxRouter,
        string $remoteLabel,
        Closure $onHandshakeAccepted,
    ): void {
        if ($frame->type === FrameType::Handshake) {
            $span = $this->safeStartHandshakeSpan();
            $parsed = $this->parseHandshakeFrame($frame);

            if ($parsed !== null) {
                [$peerAddr, $peerEndpoint, $handshake] = $parsed;
                $state->peerAddr = $peerAddr;
                $state->ingress = new FrameIngress($inboxRouter, $peerAddr, $this->payloadCodec, meter: $this->meter);
                $onHandshakeAccepted($peerAddr);
                // Stamp ingress time here (frame-parse), not at actor-processing time, so the phi
                // detector is fed the arrival instant regardless of membership-mailbox latency.
                $observedAt = $this->system->clock()->now();
                $this->membershipRef->tell(new HandshakeReceived($peerAddr, $peerEndpoint, $handshake, $observedAt));
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
            $this->applyHandshakeAckView($frame);

            return;
        }

        if ($frame->type === FrameType::Gossip) {
            $this->processGossipFrame($frame, $peerAddr);
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
     * Wire the frame pump for an accepted inbound PeerLink.
     */
    private function wireInboundLink(PeerLink $link, InboxRouter $inboxRouter): void
    {
        // Concurrency cap: inbound links are unauthenticated, so refuse new ones once the live
        // ceiling is reached rather than let a peer exhaust memory with endless open sockets.
        if (count($this->inboundLinks) >= $this->topology->maxInboundLinks) {
            $this->safely(fn(): mixed => $this->logger->warning('cluster.inbound.capacity_exceeded', [
                'limit' => $this->topology->maxInboundLinks,
                'peer_endpoint' => $link->remote() !== null ? (string) $link->remote() : 'unknown',
            ]));
            $link->close();

            return;
        }

        $linkId = spl_object_id($link);
        $this->inboundLinks[$linkId] = true;
        $state = new LinkState();
        $remoteLabel = $link->remote() !== null
            ? (string) $link->remote()
            : 'unknown';

        // Slowloris guard: close the link if it never completes a valid handshake in time. The
        // receive loop tolerates recv timeouts, so an unidentified link would otherwise idle forever.
        $deadline = $this->runtime->scheduleOnce(
            $this->topology->handshakeTimeout,
            function () use ($state, $link, $linkId, $remoteLabel): void {
                if ($state->peerAddr !== null) {
                    return;
                }

                unset($this->inboundLinks[$linkId]);
                $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.timeout', [
                    'peer_endpoint' => $remoteLabel,
                ]));
                $link->close();
            },
        );

        $link->onFrame(function (Frame $frame) use ($link, $state, $inboxRouter, $deadline, $remoteLabel): void {
            $this->handleLinkFrame(
                $frame,
                $state,
                $inboxRouter,
                $remoteLabel,
                function (NodeAddress $peerAddr) use ($link, $deadline): void {
                    $deadline->cancel();
                    // Re-handshake: a new inbound link supersedes any prior one for this peer. We do
                    // NOT eagerly close the prior link — in the mutual-seed mesh close() EOFs the remote
                    // peer and triggers a reconnect/re-handshake storm that starves gossip and spuriously
                    // suspects healthy peers. Just replace the map slot; the prior link is cleaned up by
                    // its own onClose (guarded so it cannot remove this newer slot), and a genuinely
                    // orphaned link EOFs on its own when the peer drops that connection.
                    $this->acceptedLinks[$peerAddr->toPathPrefix()] = $link;
                },
            );
        });

        $link->onClose(function () use ($link, $state, $linkId, $deadline): void {
            $deadline->cancel();
            unset($this->inboundLinks[$linkId]);

            $peerAddr = $state->peerAddr;

            if ($peerAddr !== null) {
                $prefix = $peerAddr->toPathPrefix();

                // Remove the accepted-link entry so the map does not leak a dead link (and so
                // processLeaveFrame no longer fans out to a stale prefix). Guard against clobbering
                // a NEWER link: a re-handshake (C2) may have already replaced this slot, in which
                // case the entry must be left intact.
                if (($this->acceptedLinks[$prefix] ?? null) === $link) {
                    unset($this->acceptedLinks[$prefix]);
                    // Tombstone the disconnected peer so a peer that hasn't yet noticed the drop cannot
                    // resurrect it via lagging gossip before the failure detector downs it (the kill /
                    // crash analogue of the graceful-Leave tombstone). Only when this link is still the
                    // current one — a re-handshake that already replaced the slot means the peer is
                    // actually still connected. Cleared when the peer re-handshakes (parseHandshakeFrame),
                    // so a transient blip self-heals via the handshake preamble.
                    $this->tombstoneDeparted($prefix);
                }

                $this->livenessThrottle->forget($prefix);
                $this->membershipRef->tell(new PeerLinkClosed($peerAddr, false));
                $this->safeDispatch(new PeerDisconnected($peerAddr));
                // Fail any in-flight asks to this node fast — the reply can't arrive over the dead link.
                $this->askRegistry->failAllForNode($peerAddr);
            }
        });
    }

    /**
     * Dial a seed endpoint: create an outbound PeerConnection and wire its frame pump to the shared
     * {@see handleLinkFrame()} state machine. The self-Handshake is sent by the PeerConnection
     * preamble on the initial connect and on every reconnect (see {@see handshakePreamble()}), so a
     * dropped seed link re-identifies us on reconnect instead of the seed silently dropping our
     * post-reconnect frames.
     */
    private function dialSeed(NodeEndpoint $seedEndpoint, InboxRouter $inboxRouter): void
    {
        $key = (string) $seedEndpoint;

        if (isset($this->outboundConns[$key])) {
            return; // Already dialed (e.g. shared hub in test multi-boot scenario).
        }

        $conn = new PeerConnection(
            $seedEndpoint,
            $this->transport,
            $this->runtime,
            $this->topology->reconnectInitialBackoff,
            $this->topology->reconnectMaxBackoff,
            logger: $this->logger,
            preamble: $this->handshakePreamble(),
        );

        $this->outboundConns[$key] = $conn;

        $state = new LinkState();
        $remoteLabel = (string) $seedEndpoint;

        $conn->onFrame(function (Frame $frame) use ($state, $inboxRouter, $remoteLabel): void {
            // Outbound connection: identity is tracked in $state; no accepted-link registration.
            $this->handleLinkFrame($frame, $state, $inboxRouter, $remoteLabel, static function (): void {});
        });
    }

    // -------------------------------------------------------------------------
    // Frame parsing helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a Handshake frame payload, register the peer's endpoint, and return
     * the parsed address, endpoint, and Handshake as a tuple.
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

        $this->endpointRegistry->register($peerAddr, $peerEndpoint);

        // A fresh handshake from this prefix means the peer is (re)joining, so clear any prior
        // Leave-dedup entry: otherwise a node that left, rejoined, and later leaves again would
        // have that second Leave silently deduped and its peers would fall back to slow
        // silence-detection instead of an immediate Down.
        unset($this->departedTombstones[$peerAddr->toPathPrefix()]);

        return [$peerAddr, $peerEndpoint, $obj];
    }

    /**
     * Apply the view snapshot in a HandshakeAck to register endpoints for members
     * we haven't seen yet. Fast-paths endpoint discovery without waiting for gossip.
     */
    private function applyHandshakeAckView(Frame $frame): void
    {
        try {
            $obj = $this->controlCodec->unpackHandshakeAck($frame->payload);
        } catch (Throwable) {
            return;
        }

        if (!$obj->accepted) {
            return;
        }

        foreach ($obj->view as $prefix => $endpointStr) {
            try {
                $addr = self::parseNodeAddress($prefix);
                $endpoint = NodeEndpoint::fromString($endpointStr);

                if ($addr !== null) {
                    $this->endpointRegistry->register($addr, $endpoint);
                }
            } catch (Throwable) {
                // Skip malformed entries; gossip will provide them later.
            }
        }
    }

    /**
     * Process an inbound Gossip frame: register member endpoints and tell membership actor.
     */
    private function processGossipFrame(Frame $frame, NodeAddress $peerAddr): void
    {
        try {
            $obj = $this->controlCodec->unpackGossip($frame->payload);
        } catch (Throwable) {
            $this->recordDecodeFailure('gossip');

            return;
        }

        $liveMembers = [];

        foreach ($obj->members as $member) {
            // Tombstone filter: drop any member we have already processed a graceful Leave for. A peer
            // that has not yet learned of the departure keeps gossiping the node as Up; without this
            // filter that lagging gossip re-teaches (resurrects) a node we already removed, and two
            // peers can bounce it back and forth indefinitely (the classic no-tombstone resurrection).
            // The departedTombstones entry is cleared when the node re-handshakes on rejoin, after which
            // its gossip flows again.
            if (isset($this->departedTombstones[$member['address']])) {
                continue;
            }

            try {
                $addr = self::parseNodeAddress($member['address']);
                $endpoint = NodeEndpoint::fromString($member['endpoint']);

                if ($addr !== null) {
                    $this->endpointRegistry->register($addr, $endpoint);
                }
            } catch (Throwable) {
                // Skip malformed members.
            }

            $liveMembers[] = $member;
        }

        $payload = $liveMembers === $obj->members
            ? $obj
            : new GossipPayload($liveMembers, $obj->registrations);

        $this->membershipRef->tell(new GossipReceived($peerAddr, $payload));
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
     * Record a departed peer — one that sent a graceful Leave or whose link definitively closed — in
     * the bounded tombstone set ({@see $departedTombstones}) so lagging gossip cannot resurrect it before
     * the failure detector downs it. FIFO-evicts at the cap. Cleared on the peer's next handshake
     * ({@see parseHandshakeFrame}), so a transient disconnect self-heals on reconnect.
     */
    private function tombstoneDeparted(string $prefix): void
    {
        if (isset($this->departedTombstones[$prefix])) {
            return;
        }

        if (count($this->departedTombstones) >= self::MAX_DEPARTED_TOMBSTONES) {
            array_shift($this->departedTombstones);
        }

        $this->departedTombstones[$prefix] = true;
    }

    private function processLeaveFrame(Frame $frame, ?NodeAddress $senderAddr): void
    {
        try {
            $payload = $this->controlCodec->unpackLeave($frame->payload);
        } catch (Throwable) {
            $this->recordDecodeFailure('leave');

            return;
        }

        $leavingAddr = self::parseNodeAddress($payload->node);

        if ($leavingAddr === null || $leavingAddr->toPathPrefix() === $this->selfAddress->toPathPrefix()) {
            return;
        }

        // Dedup: if we have already processed a Leave for this node, skip re-delivery and relay.
        $leavingPrefix = $leavingAddr->toPathPrefix();

        if (isset($this->departedTombstones[$leavingPrefix])) {
            return;
        }

        $this->tombstoneDeparted($leavingPrefix);

        $this->membershipRef->tell(new LeaveReceived($leavingAddr));

        // A graceful Leave means the peer is definitively gone: evict and close our outbound
        // connection to it so its reconnect loop stops. We deliberately do NOT evict on a
        // phi/timeout NodeDown, which may be a false positive that must be allowed to heal.
        $this->evictOutbound($leavingAddr);

        // Forward to all accepted peers except the leaving node and the frame sender.
        $senderPrefix = $senderAddr?->toPathPrefix();

        foreach (array_keys($this->acceptedLinks) as $prefix) {
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
     * Build a Handshake payload announcing this node's identity and advertise endpoint.
     */
    private function buildSelfHandshake(): Handshake
    {
        $handshake = Handshake::forSelf($this->topology);

        return $this->authenticator?->sign($handshake) ?? $handshake;
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
    private function handshakePreamble(): Closure
    {
        return fn(): Frame => new Frame(
            FrameType::Handshake,
            $this->controlCodec->packHandshake($this->buildSelfHandshake()),
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
     * Instruments nexus.cluster.frames.sent and nexus.cluster.bytes.sent via the injected meter.
     *
     * Note: nexus.cluster.send_buffer.dropped is not emitted on this path because drop detection
     * requires PeerConnection queue-overflow visibility, which is not threaded to this sink.
     * MeshOutboundSink retains all three send-side metrics for its own direct callers.
     *
     * @param Closure(string, Frame): void $sender
     */
    private static function buildOutboundSink(
        Closure $sender,
        MessagePayloadCodec $payloadCodec,
        Meter $meter,
    ): OutboundSink {
        return new class ($sender, $payloadCodec, $meter) implements OutboundSink {
            private ?Counter $framesSent = null;

            private ?Histogram $bytesSent = null;

            public function __construct(
                private readonly Closure $sender,
                private readonly MessagePayloadCodec $payloadCodec,
                private readonly Meter $meter,
            ) {}

            #[Override]
            public function send(NodeAddress $target, MessagePayload $payload): void
            {
                $bytes = $this->payloadCodec->pack($payload);
                $this->safely(fn(): mixed => $this->bytesSentHistogram()->record(strlen($bytes)));
                ($this->sender)($target->toPathPrefix(), new Frame(FrameType::Message, $bytes));
                $this->safely(fn(): mixed => $this->framesSentCounter()->add(1, ['frame.type' => 'message']));
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
                    'Cluster frames sent to remote peers',
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
