<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use BadMethodCallException;
use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackHub;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\EventDispatcherMembershipEventPublisher;
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
use Monadial\Nexus\Cluster\Tcp\Membership\RandomPeerSelector;
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
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Swoole\SwooleMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextInjector;
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
use function ltrim;
use function preg_replace;
use function strlen;

/**
 * @psalm-api
 *
 * The cluster node bootstrap: wires all C1.6a–d components into a running cluster node.
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
 * Rejoin after Down is not wired in C1 (no `RejoinRequested` message). A node that
 * transitions to Down must restart the process to re-join.
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
     * Hard cap on remembered Leave path-prefixes. Leave frames are unauthenticated, so an
     * unbounded dedup set is a memory-exhaustion vector (a peer can relay Leaves for endless
     * fabricated identities). At capacity the oldest remembered prefix is evicted; the worst
     * case of re-evicting a still-relevant entry is a single redundant LeaveReceived, not a fault.
     */
    private const int MAX_PROCESSED_LEAVES = 10_000;

    /** @var array<string, PeerLink> Accepted inbound links keyed by NodeAddress::toPathPrefix() */
    private array $acceptedLinks = [];

    /** @var array<string, PeerConnection> Outbound connections keyed by (string) NodeEndpoint */
    private array $outboundConns = [];

    /** @var array<string, true> Path-prefixes for which a Leave has already been processed; prevents duplicate delivery on relay-back. */
    private array $processedLeaves = [];

    private ?Counter $handshakeRejected = null;

    private function __construct(
        private readonly NodeAddress $selfAddress,
        private readonly ClusterTopology $topology,
        private readonly LocalActorRegistry $localRegistry,
        private readonly ClusterRefFactory $refFactory,
        private readonly ActorRef $membershipRef,
        private readonly MeshTransport $transport,
        private readonly MutableEndpointRegistry $endpointRegistry,
        private readonly MessageSerializer $frameSerializer,
        private readonly Runtime $runtime,
        private readonly ActorSystem $system,
        private readonly Tracer $tracer,
        private readonly Meter $meter,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Boot a cluster node from the given topology, wiring all collaborators.
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
        $localDelivery = new LocalDelivery($localRegistry);
        $meter = $observability->meter();
        $askRegistry = new TcpAskRegistry($runtime, meter: $meter);

        // 4. User-message codec. When $userTypes is non-null it shares the registry with the
        //    frame serializer so user types are reachable on both encode and decode paths.
        //    Falls back to a separate empty TypeRegistry when null (membership-only setups).
        $codec = new ClusterMessageCodec($frameSerializer, $userTypes ?? new TypeRegistry());

        // 5. Transport (override or auto-select).
        $meshTransport = $transport ?? self::selectTransport($runtime, $topology);

        // 6. Lazy sender closure: resolved after the node is constructed.
        //    The closure is never invoked before $system->run(); by then $selfNode is non-null.
        /** @var ClusterNode|null $selfNode */
        $selfNode = null;

        /**
         * @psalm-suppress TypeDoesNotContainType Psalm cannot track by-ref mutation of $selfNode
         *                 across the closure boundary; the variable is always set before first call.
         * @psalm-suppress MixedMethodCall Same root cause: Psalm types $selfNode as null inside the closure.
         */
        $sender = static function (string $prefix, Frame $frame) use (&$selfNode): void {
            if ($selfNode !== null) {
                $selfNode->sendByPrefix($prefix, $frame);
            }
        };

        // 7. Outbound sink for user messages (ClusterRef::tell / ask).
        $outboundSink = self::buildOutboundSink($sender, $frameSerializer, $meter);

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

        $refFactory = new ClusterRefFactory(
            $topology->self,
            $outboundSink,
            $localDelivery,
            $askRegistry,
            $codec,
            $traceInjector,
            $tracer,
            $meter,
        );

        // 9. Membership collaborators.
        $effectInterpreter = new TcpMembershipEffectInterpreter($topology, $frameSerializer, $sender, $meter);
        $eventPublisher = new EventDispatcherMembershipEventPublisher($system->eventDispatcher());

        $service = new MembershipService($topology, $topology->maxNoHeartbeat);
        $detector = new PhiAccrualDetector(
            $topology->phiSampleSize,
            (float) $topology->phiMinStdDev->toMillis(),
        );

        $membershipActor = new MembershipActor(
            service: $service,
            detector: $detector,
            selector: new RandomPeerSelector(),
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
            frameSerializer: $frameSerializer,
            runtime: $runtime,
            system: $system,
            tracer: $tracer,
            meter: $meter,
            dispatcher: $system->eventDispatcher(),
            logger: $logger,
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

        /**
         * @psalm-suppress InvalidArgument Behavior::receive generic constraint; the closure
         *                 handles a heterogeneous reply (ClusterView) from the membership actor.
         */
        $viewBehavior = Behavior::receive(
            static function ($ctx, object $msg) use (&$captured): Behavior {
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
     * Broadcast Leave to all connected peers, close all connections, and close the transport.
     * Call before or during ActorSystem shutdown to signal graceful departure.
     */
    public function shutdown(): void
    {
        $leavePayload = new LeavePayload($this->selfAddress->toPathPrefix());
        $leaveBytes = $this->frameSerializer->serialize($leavePayload);
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
                );
            }

            $this->outboundConns[$key]->sendFrame($frame);

            return;
        }

        // Endpoint not yet in registry — fall back to the accepted inbound link.
        if (isset($this->acceptedLinks[$prefix])) {
            $this->acceptedLinks[$prefix]->sendFrame($frame);
        }
    }

    // -------------------------------------------------------------------------
    // Frame pump wiring
    // -------------------------------------------------------------------------

    /**
     * @psalm-api
     *
     * Receptionist pattern for service discovery. Arrives in the C2 track.
     *
     * @throws BadMethodCallException Always.
     */
    public function receptionist(): never
    {
        throw new BadMethodCallException('receptionist arrives in C2');
    }

    /**
     * Wire the frame pump for an accepted inbound PeerLink.
     */
    private function wireInboundLink(PeerLink $link, InboxRouter $inboxRouter): void
    {
        /** @var NodeAddress|null $peerAddr Learned from the first Handshake frame. */
        $peerAddr = null;

        /** @var FrameIngress|null $ingress Created once peerAddr is known. */
        $ingress = null;

        $link->onFrame(function (Frame $frame) use ($link, &$peerAddr, &$ingress, $inboxRouter): void {
            if ($frame->type === FrameType::Handshake) {
                $span = $this->safeStartHandshakeSpan();
                $parsed = $this->parseHandshakeFrame($frame);

                if ($parsed !== null) {
                    [$peerAddr, $peerEndpoint, $handshake] = $parsed;
                    $this->acceptedLinks[$peerAddr->toPathPrefix()] = $link;
                    $ingress = new FrameIngress($inboxRouter, $peerAddr, $this->frameSerializer, meter: $this->meter);
                    $this->membershipRef->tell(new HandshakeReceived($peerAddr, $peerEndpoint, $handshake));
                    $this->safeSpanAttribute($span, 'nexus.cluster.peer', $peerAddr->toPathPrefix());
                    $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'accepted');
                    $this->safeDispatch(new PeerConnected($peerAddr, $peerEndpoint));
                } else {
                    $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'rejected');
                    $this->safeRecordRejection();
                    $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.rejected', [
                        'peer_endpoint' => $link->remote() !== null ? (string) $link->remote() : 'unknown',
                        'reason' => 'parse_failure',
                    ]));
                }

                $this->safeEndSpan($span);

                return;
            }

            if ($frame->type === FrameType::HandshakeAck) {
                $this->applyHandshakeAckView($frame);

                return;
            }

            if ($peerAddr === null) {
                return; // Not yet identified; ignore frames until Handshake arrives.
            }

            if ($frame->type === FrameType::Gossip) {
                $this->processGossipFrame($frame, $peerAddr);
                // Gossip is the steady-state heartbeat: receiving it proves the peer
                // is alive, so it MUST feed the failure detector. Without this the phi
                // detector starves once traffic goes quiet and falsely suspects an
                // idle-but-alive peer (there is no separate Ping/Pong heartbeat).
                $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null));

                return;
            }

            if ($frame->type === FrameType::Leave) {
                $this->processLeaveFrame($frame, $peerAddr);

                return;
            }

            if ($frame->type === FrameType::Message && $ingress !== null) {
                $ingress->ingest($frame);
                $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null));

                return;
            }

            $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null));
        });

        $link->onClose(function () use (&$peerAddr): void {
            if ($peerAddr !== null) {
                $this->membershipRef->tell(new PeerLinkClosed($peerAddr, false));
                $this->safeDispatch(new PeerDisconnected($peerAddr));
            }
        });
    }

    /**
     * Dial a seed endpoint: create an outbound PeerConnection, wire per-connection state
     * (peerAddr + ingress) via reference capture, and send our Handshake as the first frame.
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
        );

        $this->outboundConns[$key] = $conn;

        /** @var NodeAddress|null $peerAddr Learned from the first Handshake on this connection. */
        $peerAddr = null;

        /** @var FrameIngress|null $ingress Created once peerAddr is known. */
        $ingress = null;

        $conn->onFrame(function (Frame $frame) use (&$peerAddr, &$ingress, $inboxRouter, $seedEndpoint): void {
            if ($frame->type === FrameType::Handshake) {
                $span = $this->safeStartHandshakeSpan();
                $parsed = $this->parseHandshakeFrame($frame);

                if ($parsed !== null) {
                    [$peerAddr, $peerEndpoint, $handshake] = $parsed;
                    $ingress = new FrameIngress($inboxRouter, $peerAddr, $this->frameSerializer, meter: $this->meter);
                    $this->membershipRef->tell(new HandshakeReceived($peerAddr, $peerEndpoint, $handshake));
                    $this->safeSpanAttribute($span, 'nexus.cluster.peer', $peerAddr->toPathPrefix());
                    $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'accepted');
                    $this->safeDispatch(new PeerConnected($peerAddr, $peerEndpoint));
                } else {
                    $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'rejected');
                    $this->safeRecordRejection();
                    $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.rejected', [
                        'peer_endpoint' => (string) $seedEndpoint,
                        'reason' => 'parse_failure',
                    ]));
                }

                $this->safeEndSpan($span);

                return;
            }

            if ($frame->type === FrameType::HandshakeAck) {
                $this->applyHandshakeAckView($frame);

                return;
            }

            if ($peerAddr === null) {
                return;
            }

            if ($frame->type === FrameType::Gossip) {
                $this->processGossipFrame($frame, $peerAddr);
                // Gossip is the steady-state heartbeat: receiving it proves the peer
                // is alive, so it MUST feed the failure detector. Without this the phi
                // detector starves once traffic goes quiet and falsely suspects an
                // idle-but-alive peer (there is no separate Ping/Pong heartbeat).
                $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null));

                return;
            }

            if ($frame->type === FrameType::Leave) {
                $this->processLeaveFrame($frame, $peerAddr);

                return;
            }

            if ($frame->type === FrameType::Message && $ingress !== null) {
                $ingress->ingest($frame);
                $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null));

                return;
            }

            $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null));
        });

        // Send our Handshake as the first frame so the seed can identify us.
        $conn->sendFrame(new Frame(
            FrameType::Handshake,
            $this->frameSerializer->serialize($this->buildSelfHandshake()),
        ));
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
            $obj = $this->frameSerializer->deserialize($frame->payload, 'cluster.handshake');
        } catch (Throwable) {
            return null;
        }

        if (!$obj instanceof Handshake) {
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

        $peerAddr = new NodeAddress(
            $obj->node['cluster'] ?? 'unknown',
            $obj->node['datacenter'] ?? 'unknown',
            $obj->node['application'] ?? 'unknown',
            $obj->node['node'] ?? 'unknown',
        );

        try {
            $peerEndpoint = NodeEndpoint::fromString($obj->advertise);
        } catch (Throwable) {
            return null;
        }

        $this->endpointRegistry->register($peerAddr, $peerEndpoint);

        return [$peerAddr, $peerEndpoint, $obj];
    }

    /**
     * Apply the view snapshot in a HandshakeAck to register endpoints for members
     * we haven't seen yet. Fast-paths endpoint discovery without waiting for gossip.
     */
    private function applyHandshakeAckView(Frame $frame): void
    {
        try {
            $obj = $this->frameSerializer->deserialize($frame->payload, 'cluster.handshake_ack');
        } catch (Throwable) {
            return;
        }

        if (!$obj instanceof HandshakeAck || !$obj->accepted) {
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
            $obj = $this->frameSerializer->deserialize($frame->payload, 'cluster.gossip');
        } catch (Throwable) {
            return;
        }

        if (!$obj instanceof GossipPayload) {
            return;
        }

        foreach ($obj->members as $member) {
            try {
                $addr = self::parseNodeAddress($member['address']);
                $endpoint = NodeEndpoint::fromString($member['endpoint']);

                if ($addr !== null) {
                    $this->endpointRegistry->register($addr, $endpoint);
                }
            } catch (Throwable) {
                // Skip malformed members.
            }
        }

        $this->membershipRef->tell(new GossipReceived($peerAddr, $obj));
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
    private function processLeaveFrame(Frame $frame, ?NodeAddress $senderAddr): void
    {
        try {
            $payload = $this->frameSerializer->deserialize($frame->payload, 'cluster.leave');
        } catch (Throwable) {
            return;
        }

        if (!$payload instanceof LeavePayload) {
            return;
        }

        $leavingAddr = self::parseNodeAddress($payload->node);

        if ($leavingAddr === null || $leavingAddr->toPathPrefix() === $this->selfAddress->toPathPrefix()) {
            return;
        }

        // Dedup: if we have already processed a Leave for this node, skip re-delivery and relay.
        $leavingPrefix = $leavingAddr->toPathPrefix();

        if (isset($this->processedLeaves[$leavingPrefix])) {
            return;
        }

        if (count($this->processedLeaves) >= self::MAX_PROCESSED_LEAVES) {
            array_shift($this->processedLeaves);
        }

        $this->processedLeaves[$leavingPrefix] = true;

        $this->membershipRef->tell(new LeaveReceived($leavingAddr));

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
        return Handshake::forSelf($this->topology);
    }

    /**
     * Build the cluster frame serializer: MessagePack with all wire payload VOs registered, plus
     * any user-defined types pre-populated in `$userTypes`. Cluster wire types are always added.
     *
     * The caller's `$userTypes` is mutated in-place (cluster types appended), so the same
     * registry instance can be reused for the user-message codec without extra copying.
     */
    private static function buildSerializer(?TypeRegistry $userTypes): MessageSerializer
    {
        $registry = $userTypes ?? new TypeRegistry();
        $registry->registerFromAttribute(GossipPayload::class);
        $registry->registerFromAttribute(Handshake::class);
        $registry->registerFromAttribute(HandshakeAck::class);
        $registry->registerFromAttribute(LeavePayload::class);
        $registry->registerFromAttribute(MessagePayload::class);

        return new MessagePackMessageSerializer($registry);
    }

    /**
     * Auto-select the transport based on available extensions and runtime type.
     * Override via the $transport parameter in boot() for tests.
     *
     * @psalm-suppress UndefinedClass  SwooleMeshTransport / SwooleRuntime are optional; only loaded with ext-swoole.
     * @psalm-suppress InvalidArgument Same reason: SwooleRuntime class string unavailable without ext-swoole.
     */
    private static function selectTransport(Runtime $runtime, ClusterTopology $topology): MeshTransport
    {
        if (extension_loaded('swoole') && $runtime instanceof SwooleRuntime) {
            return new SwooleMeshTransport($runtime, $topology->tls);
        }

        return new LoopbackMeshTransport(new LoopbackHub(), $runtime);
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
        MessageSerializer $frameSerializer,
        Meter $meter,
    ): OutboundSink
    {
        return new class ($sender, $frameSerializer, $meter) implements OutboundSink {
            private ?Counter $framesSent = null;

            private ?Histogram $bytesSent = null;

            public function __construct(
                private readonly Closure $sender,
                private readonly MessageSerializer $frameSerializer,
                private readonly Meter $meter,
            ) {}

            #[Override]
            public function send(NodeAddress $target, MessagePayload $payload): void
            {
                $bytes = $this->frameSerializer->serialize($payload);
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

        return new NodeAddress($parts[1], $parts[2], $parts[3], $parts[4]);
    }
}
