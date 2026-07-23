<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\EvictPeer;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\LinkClosed;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RecordTombstone;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterIdentifiedLink;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterUnauthenticatedEndpoint;
use Monadial\Nexus\Cluster\Tcp\DeliveryOutcome;
use Monadial\Nexus\Cluster\Tcp\Membership\LivenessThrottle;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipService;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GossipReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\LeaveReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLivenessObserved;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerAuthenticator;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerConnected;
use Monadial\Nexus\Cluster\Tcp\Messaging\FrameIngress;
use Monadial\Nexus\Cluster\Tcp\Messaging\InboxRouter;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Cluster\Tcp\Protocol\FrameType;
use Monadial\Nexus\Cluster\Tcp\Transport\LinkClosedNotice;
use Monadial\Nexus\Cluster\Tcp\Transport\LinkFrame;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use Monadial\Nexus\Runtime\Duration;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_filter;
use function array_keys;
use function array_values;
use function count;
use function explode;
use function ltrim;
use function time;

/**
 * @psalm-api
 *
 * The per-link frame state machine, actorized: one instance is spawned per transport link (an
 * accepted inbound {@see PeerLink}, or a dialed-outbound seed connection) and drives it through
 * two behaviors — `Unidentified` then `Identified` — via `become`. This replaces
 * `ClusterNode::handleLinkFrame()`'s single synchronous state machine (driven off a mutable
 * `LinkState` shared by both callers) with the exact same logic, moved verbatim, split across the
 * two behaviors it always implicitly had.
 *
 * **Unidentified**: only a {@see FrameType::Handshake} frame is processed (parse → cluster/protocol
 * match → HMAC verify → identity/endpoint parse → SEC-008 re-identify guard, trivially satisfied
 * here since there is no prior identity to conflict with). On acceptance: tell
 * {@see $supervisorRef} {@see RegisterIdentifiedLink} (the C10 accepted-link-slot write, the
 * departed-tombstone clear, and the `HandshakeReceived` tell to membership all happen there, in its
 * own serialized mailbox — see {@see ConnectionSupervisor}); dispatch {@see PeerConnected}; cancel
 * the Slowloris deadline; **become** `Identified`. Every other frame type is silently dropped — C2
 * (zero pre-identification ingress) holds structurally, not by a runtime check. A
 * {@see ReceiveTimeout} (armed only when `$handshakeTimeout` is non-null — the accepted-inbound
 * path) closes the link and stops.
 *
 * **Identified**: the non-handshake frame branches from the old `handleLinkFrame` — ack-view,
 * gossip, leave, message — move here verbatim. A SECOND {@see FrameType::Handshake} is also handled
 * here (this is where the SEC-008 re-identify guard actually bites): same-prefix re-handshake (e.g.
 * on endpoint failover) re-registers and stays Identified; a conflicting identity is rejected and
 * counted, the link stays on its current identity.
 *
 * `onSignal` handlers do NOT survive `become` (a framework fact, not a bug) — each behavior
 * re-attaches its own PostStop handler (link close, idempotent).
 *
 * Ingress-timing note (C3): `$observedAt`/`$monotonicNs` are stamped by the pump — the acceptor's
 * frame callback, or the outbound seed's connection callback — BEFORE the {@see LinkFrame} message
 * is offered to this actor's mailbox, and consumed here as-is rather than recomputed. This actor's
 * own mailbox is a new potential source of processing lag that did not exist when `handleLinkFrame`
 * ran synchronously off the transport callback; recomputing "now" at dequeue time would let that
 * lag corrupt the phi-detector arrival time and the {@see LivenessThrottle} interval math. The
 * throttle's `shouldObserve` GATE still runs here (it is peer-identity-keyed, so it cannot run in
 * the transport-agnostic pump) — only the raw timestamp capture moved earlier.
 *
 * Outbound (dialed-seed) treatment: spawned with `$link: null` (no accepted-link-slot bookkeeping)
 * and `$handshakeTimeout: null` (no Slowloris deadline — the outbound path has never had one: the
 * seed's own accepted-inbound link on ITS node enforces the deadline instead) and no
 * {@see LinkClosedNotice} wiring at all (mirrors `ClusterNode::dialSeed()`'s prior behaviour
 * exactly: an outbound `PeerConnection`'s reconnects are invisible here, and there was never an
 * onLinkClosed-equivalent for this path pre-actorization either).
 *
 * A rejected identification (parse failure or a reidentify mismatch) never reaches
 * {@see observeLiveness()} — mirrors the audited SEC-008 check 5 invariant from `handleLinkFrame`.
 */
final class InboundLinkActor
{
    /** Bounded mailbox capacity for a per-link actor — DropNewest overflow, see {@see props()}. */
    private const int MAILBOX_CAPACITY = 1_024;

    /** SEC-008 controlRejected `check` attribute values — moved verbatim from `ClusterNode`. */
    private const string CHECK_LEAVE_UNSIGNED = 'leave_unsigned';

    private const string CHECK_LEAVE_REPLAY = 'leave_replay';

    private const string CHECK_REIDENTIFY_MISMATCH = 'reidentify_mismatch';

    private const string CHECK_ACK_VIEW_AUTHORITY = 'ack_view_authority';

    private const string CHECK_GOSSIP_ENDPOINT_AUTHORITY = 'gossip_endpoint_authority';

    private ?Counter $handshakeRejected = null;

    private ?Counter $framesDecodeFailed = null;

    private ?Counter $controlRejected = null;

    /**
     * @param ActorRef<object> $supervisorRef {@see ConnectionSupervisor} — routing-state writes
     *        (`RegisterIdentifiedLink`, `RegisterUnauthenticatedEndpoint`, `RecordTombstone`,
     *        `EvictPeer`, `LinkClosed`).
     * @param ActorRef<object> $membershipRef Membership actor — `GossipReceived`, `LeaveReceived`,
     *        `PeerLivenessObserved`.
     * @param Closure(string, Frame): DeliveryOutcome $egress `ClusterNode::sendByPrefix` — used only
     *        for the Leave star-relay (forwarding to other accepted peers).
     * @param ?PeerLink $link The accepted inbound link this actor identifies, forwarded on
     *        {@see RegisterIdentifiedLink} for the C10 accepted-link-slot write and closed on
     *        PostStop/timeout; null on the dialed-outbound path (no accepted-link bookkeeping there).
     * @param ?Duration $handshakeTimeout Slowloris deadline armed via `setReceiveTimeout()` while
     *        Unidentified; null arms none (the dialed-outbound path — see class docblock).
     */
    public function __construct(
        private readonly ActorRef $supervisorRef,
        private readonly ActorRef $membershipRef,
        private readonly RoutingSnapshotHolder $snapshotHolder,
        private readonly ?PeerAuthenticator $authenticator,
        private readonly ClusterTopology $topology,
        private readonly MessagePayloadCodec $payloadCodec,
        private readonly ControlFrameCodec $controlCodec,
        private readonly InboxRouter $inboxRouter,
        private readonly LivenessThrottle $livenessThrottle,
        private readonly Closure $egress,
        private readonly Tracer $tracer,
        private readonly Meter $meter,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly ?PeerLink $link,
        private readonly string $remoteLabel,
        private readonly ?Duration $handshakeTimeout,
    ) {}

    /**
     * @return Props<object>
     */
    public function props(): Props
    {
        $actor = $this;
        $timeout = $this->handshakeTimeout;

        $behavior = Behavior::setup(
            /**
             * @param ActorContext<object> $ctx
             * @return Behavior<object>
             */
            static function (ActorContext $ctx) use ($actor, $timeout): Behavior {
                if ($timeout !== null) {
                    $ctx->setReceiveTimeout($timeout);
                }

                return $actor->unidentified();
            },
        );

        return Props::fromBehavior($behavior)->withBoundedMailbox(self::MAILBOX_CAPACITY);
    }

    // -------------------------------------------------------------------------
    // Behaviors
    // -------------------------------------------------------------------------

    /**
     * @return Behavior<object>
     */
    private function unidentified(): Behavior
    {
        $actor = $this;

        return Behavior::receive(
            /**
             * @param ActorContext<object> $ctx
             * @return Behavior<object>
             */
            static function (ActorContext $ctx, object $msg) use ($actor): Behavior {
                return match (true) {
                    $msg instanceof LinkFrame && $msg->frame->type === FrameType::Handshake
                        => $actor->handleHandshake($ctx, $msg, null),
                    // C2 (zero pre-identification ingress): every other frame type is silently
                    // dropped — structural, not a runtime allow-list.
                    $msg instanceof LinkFrame => Behavior::same(),
                    $msg instanceof LinkClosedNotice => Behavior::stopped(),
                    default => Behavior::same(),
                };
            },
        )->onSignal(
            /**
             * @param ActorContext<object> $_ctx
             * @return Behavior<object>
             */
            static function (ActorContext $_ctx, Signal $signal) use ($actor): Behavior {
                if ($signal instanceof ReceiveTimeout) {
                    $actor->closeLink();

                    return Behavior::stopped();
                }

                if ($signal instanceof PostStop) {
                    $actor->closeLink();
                }

                return Behavior::same();
            },
        );
    }

    /**
     * @return Behavior<object>
     */
    private function identified(NodeAddress $peerAddr, string $boundAdvertise, FrameIngress $ingress): Behavior
    {
        $actor = $this;

        return Behavior::receive(
            /**
             * @param ActorContext<object> $ctx
             * @return Behavior<object>
             */
            static function (ActorContext $ctx, object $msg) use ($actor, $peerAddr, $boundAdvertise, $ingress): Behavior {
                if ($msg instanceof LinkClosedNotice) {
                    return $actor->handleLinkClosedNotice($peerAddr);
                }

                if (!$msg instanceof LinkFrame) {
                    return Behavior::same();
                }

                return match ($msg->frame->type) {
                    FrameType::Handshake => $actor->handleHandshake($ctx, $msg, $peerAddr),
                    FrameType::HandshakeAck => $actor->onHandshakeAck($msg->frame, $peerAddr, $boundAdvertise),
                    FrameType::Gossip => $actor->onGossip($msg, $peerAddr, $boundAdvertise),
                    FrameType::Leave => $actor->onLeave($msg->frame, $peerAddr),
                    FrameType::Message => $actor->onMessage($msg, $peerAddr, $ingress),
                    default => $actor->onOtherFrame($msg, $peerAddr),
                };
            },
        )->onSignal(
            /**
             * @param ActorContext<object> $_ctx
             * @return Behavior<object>
             */
            static function (ActorContext $_ctx, Signal $signal) use ($actor): Behavior {
                if ($signal instanceof PostStop) {
                    $actor->closeLink();
                }

                return Behavior::same();
            },
        );
    }

    // -------------------------------------------------------------------------
    // Handshake (shared by Unidentified and Identified — SEC-008 checks 1-2 apply to both)
    // -------------------------------------------------------------------------

    /**
     * Parse and admit a Handshake frame. `$currentPeerAddr` is null when called from Unidentified
     * (the re-identify guard is then trivially satisfied — there is no prior identity to conflict
     * with) and the already-bound peer address when called from Identified (a genuine re-handshake,
     * e.g. on endpoint failover — same-prefix proceeds unchanged, C10 supersede semantics).
     *
     * @return Behavior<object>
     */
    private function handleHandshake(ActorContext $ctx, LinkFrame $msg, ?NodeAddress $currentPeerAddr): Behavior
    {
        $span = $this->safeStartHandshakeSpan();
        $parsed = $this->parseHandshakeFrame($msg->frame);

        if ($parsed === null) {
            $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'rejected');
            $this->safeRecordRejection();
            $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.rejected', [
                'peer_endpoint' => $this->remoteLabel,
                'reason' => 'parse_failure',
            ]));
            $this->safeEndSpan($span);

            return Behavior::same();
        }

        [$peerAddr, $peerEndpoint, $handshake] = $parsed;

        if ($this->isReidentifyMismatch($currentPeerAddr, $peerAddr)) {
            $this->recordControlRejected(self::CHECK_REIDENTIFY_MISMATCH);
            $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'rejected');
            $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.reidentify_rejected', [
                'claimed_peer' => $peerAddr->toPathPrefix(),
                'existing_peer' => $currentPeerAddr?->toPathPrefix(),
                'peer_endpoint' => $this->remoteLabel,
            ]));
            $this->safeEndSpan($span);

            return Behavior::same();
        }

        // Side effects only AFTER the re-identify check: registry write, verified-set mark,
        // tombstone clear, C10 accepted-link supersede, and the HandshakeReceived tell to
        // membership all happen inside ConnectionSupervisor's own serialized mailbox, in that
        // order — the load-bearing ordering invariant (registration precedes membership processing
        // the handshake it produced) rides the actor hop rather than a synchronous call stack.
        $ingress = new FrameIngress($this->inboxRouter, $peerAddr, $this->payloadCodec, meter: $this->meter);
        $this->supervisorRef->tell(new RegisterIdentifiedLink(
            $peerAddr,
            $peerEndpoint,
            $this->link,
            $handshake,
            $msg->observedAt,
        ));
        $this->safeSpanAttribute($span, 'nexus.cluster.peer', $peerAddr->toPathPrefix());
        $this->safeSpanAttribute($span, 'nexus.cluster.handshake.outcome', 'accepted');
        $this->safeDispatch(new PeerConnected($peerAddr, $peerEndpoint));
        $this->safeEndSpan($span);

        // Cancel the Slowloris deadline — a no-op once already disabled (Identified re-handshake).
        $ctx->setReceiveTimeout(null);

        return $this->identified($peerAddr, $handshake->advertise, $ingress);
    }

    /**
     * SEC-008 check 2 (re-identification pinning): whether a freshly-parsed Handshake identity
     * conflicts with the identity this link already bound. `$currentPeerAddr` is null from
     * Unidentified (trivially never a mismatch). A same-prefix re-handshake (the peer re-announcing
     * itself, e.g. on endpoint failover) is NOT a mismatch and proceeds unchanged (C10 supersede).
     */
    private function isReidentifyMismatch(?NodeAddress $currentPeerAddr, NodeAddress $parsedPeerAddr): bool
    {
        return $currentPeerAddr !== null && $currentPeerAddr->toPathPrefix() !== $parsedPeerAddr->toPathPrefix();
    }

    /**
     * Parse and validate a Handshake frame payload, returning the parsed address, endpoint, and
     * Handshake as a tuple — or null on any validation failure.
     *
     * PURE with respect to routing state: no registry write, no verified-set marking, no tombstone
     * clear happens here — {@see ConnectionSupervisor} applies those via the
     * {@see RegisterIdentifiedLink} tell, sent only after the re-identification check passes, so a
     * rejected impersonation attempt cannot poison the claimed identity's routing state. The one
     * deliberate exception: a successful HMAC verify() consumes the frame's nonce in the replay
     * guard, so even a subsequently-rejected frame's signature cannot be replayed at this node.
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
        // cluster name or protocol version does not match ours must never have its Message frames
        // routed to local actors.
        if ($obj->clusterName !== $this->topology->clusterName
            || $obj->protocolVersion !== MembershipService::PROTOCOL_VERSION) {
            $this->safely(fn(): mixed => $this->logger->debug('cluster.handshake.mismatch', [
                'expected_cluster' => $this->topology->clusterName,
                'peer_cluster' => $obj->clusterName,
                'peer_protocol' => $obj->protocolVersion,
            ]));

            return null;
        }

        // Authenticate BEFORE any ingress is wired: a peer that cannot prove it holds the shared
        // cluster secret is rejected here, so it never joins the view or delivers a frame.
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
            // A peer whose identity segments carry non-URL-safe characters is rejected as
            // malformed rather than admitted.
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

    // -------------------------------------------------------------------------
    // Identified: non-handshake frame branches (moved verbatim from ClusterNode::handleLinkFrame)
    // -------------------------------------------------------------------------

    /**
     * @return Behavior<object>
     */
    private function onHandshakeAck(Frame $frame, NodeAddress $peerAddr, string $boundAdvertise): Behavior
    {
        $this->applyHandshakeAckView($frame, $peerAddr, $boundAdvertise);

        return Behavior::same();
    }

    /**
     * Apply the view snapshot in a HandshakeAck to register endpoints for members we haven't seen
     * yet. Fast-paths endpoint discovery without waiting for gossip.
     *
     * SEC-008 check 3 (ack-view authority): an entry whose prefix is already tombstoned is skipped
     * unconditionally. The entry naming the ack SENDER's own prefix must additionally match the
     * endpoint its Handshake HMAC-bound to this link (`$boundAdvertise`); a mismatch is rejected
     * and counted rather than silently registered. Every entry then flows through
     * {@see registerUnauthenticatedEndpoint()}, the SAME write policy gossip uses (check 4).
     */
    private function applyHandshakeAckView(Frame $frame, NodeAddress $peerAddr, string $boundAdvertise): void
    {
        try {
            $obj = $this->controlCodec->unpackHandshakeAck($frame->payload);
        } catch (Throwable) {
            return;
        }

        if (!$obj->accepted) {
            return;
        }

        $senderPrefix = $peerAddr->toPathPrefix();
        $tombstones = $this->snapshotHolder->current()->tombstones;

        foreach ($obj->view as $prefix => $endpointStr) {
            if (isset($tombstones[$prefix])) {
                continue;
            }

            if ($this->authenticator !== null && $prefix === $senderPrefix && $endpointStr !== $boundAdvertise) {
                $this->recordControlRejected(self::CHECK_ACK_VIEW_AUTHORITY);

                continue;
            }

            $this->registerUnauthenticatedEndpoint($prefix, $endpointStr, self::CHECK_ACK_VIEW_AUTHORITY);
        }
    }

    /**
     * @return Behavior<object>
     */
    private function onGossip(LinkFrame $msg, NodeAddress $peerAddr, string $boundAdvertise): Behavior
    {
        $this->processGossipFrame($msg->frame, $peerAddr, $boundAdvertise);
        // Gossip is the steady-state heartbeat: receiving it proves the peer is alive, so it MUST
        // feed the failure detector.
        $this->observeLiveness($peerAddr, $msg->monotonicNs, $msg->observedAt);

        return Behavior::same();
    }

    /**
     * Process an inbound Gossip frame: register member endpoints and tell the membership actor.
     */
    private function processGossipFrame(Frame $frame, NodeAddress $peerAddr, string $boundAdvertise): void
    {
        try {
            $obj = $this->controlCodec->unpackGossip($frame->payload);
        } catch (Throwable) {
            $this->recordDecodeFailure('gossip');

            return;
        }

        $liveMembers = [];
        $senderPrefix = $peerAddr->toPathPrefix();
        $tombstones = $this->snapshotHolder->current()->tombstones;

        foreach ($obj->members as $member) {
            // Tombstone filter: drop any member we have already processed a graceful Leave for — a
            // lagging peer's gossip must not resurrect a node we already removed.
            if (isset($tombstones[$member['address']])) {
                continue;
            }

            $this->registerGossipEndpoint($member, $senderPrefix, $boundAdvertise);

            // View merge / forwarding is unaffected by SEC-008 check 4: a member entry this node
            // declines to REGISTER is still merged into the membership view and re-gossiped — only
            // the local endpoint-registry write is restricted.
            $liveMembers[] = $member;
        }

        $payload = $liveMembers === $obj->members
            ? $obj
            : new GossipPayload($liveMembers, $obj->registrations);

        $this->membershipRef->tell(new GossipReceived($peerAddr, $payload));
    }

    /**
     * SEC-008 check 4 (gossip endpoint-write policy): register `$member`'s endpoint unless
     * authority forbids the write. A member entry naming the gossip SENDER's own prefix must match
     * the endpoint its Handshake HMAC-bound to this link — the sender cannot use gossip to redirect
     * its own endpoint. Every entry then flows through {@see registerUnauthenticatedEndpoint()}
     * (shared with the ack-view path, check 3).
     *
     * @param array{address: string, endpoint: string, incarnation: int, status: int} $member
     */
    private function registerGossipEndpoint(array $member, string $senderPrefix, string $boundAdvertise): void
    {
        $isSendersOwnEntry = $member['address'] === $senderPrefix;

        if ($this->authenticator !== null && $isSendersOwnEntry && $member['endpoint'] !== $boundAdvertise) {
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
     * entry or a gossip member entry — the single shared write policy behind SEC-008 checks 3-4.
     * Parses `$endpointStr` here (malformed entries are skipped) and delegates the actual write
     * policy to {@see ConnectionSupervisor} via a {@see RegisterUnauthenticatedEndpoint} tell.
     */
    private function registerUnauthenticatedEndpoint(string $prefix, string $endpointStr, string $rejectCheck): void
    {
        try {
            $endpoint = NodeEndpoint::fromString($endpointStr);
        } catch (Throwable) {
            return;
        }

        $this->supervisorRef->tell(new RegisterUnauthenticatedEndpoint($prefix, $endpoint, $rejectCheck));
    }

    /**
     * @return Behavior<object>
     */
    private function onLeave(Frame $frame, NodeAddress $peerAddr): Behavior
    {
        $this->processLeaveFrame($frame, $peerAddr);

        return Behavior::same();
    }

    /**
     * SEC-008 check 1: whether `$payload` is a validly self-attested Leave, recording the specific
     * rejection reason when it is not.
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

    /**
     * Parse a Leave frame payload to identify the actual leaving node, then notify the membership
     * actor and forward the frame to all other accepted peers (star-topology relay, via
     * `$this->egress`).
     */
    private function processLeaveFrame(Frame $frame, NodeAddress $senderAddr): void
    {
        try {
            $payload = $this->controlCodec->unpackLeave($frame->payload);
        } catch (Throwable) {
            $this->recordDecodeFailure('leave');

            return;
        }

        // SEC-008 check 1 (self-attesting Leave): with a cluster secret configured, a Leave must
        // carry the leaving node's own signature.
        if ($this->authenticator !== null && !$this->admitSelfAttestingLeave($this->authenticator, $payload)) {
            return;
        }

        $leavingAddr = self::parseNodeAddress($payload->node);

        if ($leavingAddr === null || $leavingAddr->toPathPrefix() === $this->topology->self->toPathPrefix()) {
            return;
        }

        // Dedup: if we have already processed a Leave for this node, skip re-delivery and relay.
        $leavingPrefix = $leavingAddr->toPathPrefix();
        $snapshot = $this->snapshotHolder->current();

        if (isset($snapshot->tombstones[$leavingPrefix])) {
            return;
        }

        $this->supervisorRef->tell(new RecordTombstone($leavingPrefix));
        $this->membershipRef->tell(new LeaveReceived($leavingAddr));

        // A graceful Leave means the peer is definitively gone: evict and close our outbound
        // connection to it so its reconnect loop stops.
        $this->supervisorRef->tell(new EvictPeer($leavingAddr));

        // Forward to all accepted peers except the leaving node and the frame sender.
        $senderPrefix = $senderAddr->toPathPrefix();

        foreach (array_keys($snapshot->acceptedLinks) as $prefix) {
            if ($prefix !== $leavingPrefix && $prefix !== $senderPrefix) {
                ($this->egress)($prefix, $frame);
            }
        }
    }

    /**
     * @return Behavior<object>
     */
    private function onMessage(LinkFrame $msg, NodeAddress $peerAddr, FrameIngress $ingress): Behavior
    {
        $ingress->ingest($msg->frame);
        $this->observeLiveness($peerAddr, $msg->monotonicNs, $msg->observedAt);

        return Behavior::same();
    }

    /**
     * @return Behavior<object>
     */
    private function onOtherFrame(LinkFrame $msg, NodeAddress $peerAddr): Behavior
    {
        $this->observeLiveness($peerAddr, $msg->monotonicNs, $msg->observedAt);

        return Behavior::same();
    }

    /**
     * Forward a liveness observation for `$peerAddr` to the membership actor, coalesced to at most
     * one per peer per detector sample interval via {@see LivenessThrottle}. `$monotonicNs` is the
     * pump-captured `hrtime(true)` reading (see class docblock's C3 note) — not recomputed here.
     */
    private function observeLiveness(NodeAddress $peerAddr, int $monotonicNs, DateTimeImmutable $observedAt): void
    {
        if ($this->livenessThrottle->shouldObserve($peerAddr->toPathPrefix(), $monotonicNs)) {
            $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null, $observedAt));
        }
    }

    // -------------------------------------------------------------------------
    // Link close
    // -------------------------------------------------------------------------

    /**
     * @return Behavior<object>
     */
    private function handleLinkClosedNotice(NodeAddress $peerAddr): Behavior
    {
        $link = $this->link;

        if ($link !== null) {
            $this->supervisorRef->tell(new LinkClosed($peerAddr, $link));
        }

        return Behavior::stopped();
    }

    /**
     * Close the underlying link, if any. Idempotent ({@see PeerLink::close()}'s own contract) and
     * a no-op on the dialed-outbound path (`$link` is null there).
     */
    private function closeLink(): void
    {
        $this->link?->close();
    }

    // -------------------------------------------------------------------------
    // Span / telemetry helpers (swallow-safe — a broken tracer/meter must never disrupt cluster ops)
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
     * Make a SEC-008 control-frame authorization rejection observable, labeled by which of the five
     * checks fired.
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

    /**
     * Parse a NodeAddress from a path-prefix string: `/cluster/{cluster}/{dc}/{app}/{node}`.
     * Returns null on malformed input. Mirrors `ClusterNode::parseNodeAddress()` /
     * `ConnectionSupervisor::parseNodeAddress()`.
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
