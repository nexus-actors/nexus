<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection;

use Closure;
use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\ClearTombstone;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\EvictPeer;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\LinkClosed;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\LinkReport;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RecordTombstone;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterIdentifiedLink;
use Monadial\Nexus\Cluster\Tcp\Connection\Message\RegisterUnauthenticatedEndpoint;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\HandshakeReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLinkClosed;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerDisconnected;
use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Monadial\Nexus\Cluster\Tcp\MutableEndpointRegistry;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_filter;
use function array_keys;
use function array_shift;
use function array_values;
use function count;
use function explode;
use function ltrim;

/**
 * @psalm-api
 *
 * Owns the connection routing state moved off `ClusterNode`: the accepted-inbound-link directory,
 * the departed-peer tombstone set, the SEC-008-verified endpoint-prefix set, and all writes to the
 * (now supervisor-internal) {@see MutableEndpointRegistry}. Every mutation publishes a fresh {@see
 * RoutingSnapshot} to the shared {@see RoutingSnapshotHolder} so `ClusterNode`'s egress and
 * admission-check reads stay lock-free.
 *
 * `InboundLinkActor` still owns the SEC-008 checks themselves, the per-link `boundAdvertise`, and
 * the parsing of untrusted wire data — this actor receives only already-validated domain values
 * and applies the resulting write, in its own serialized mailbox. That serialization
 * is what preserves the pre-actorization ordering invariant: {@see RegisterIdentifiedLink} always
 * completes its registry write, then tells the membership actor `HandshakeReceived` — in that
 * order, on this actor's single message-processing loop — so registration is always visible before
 * membership processes the handshake it produced.
 *
 * `MutableEndpointRegistry` and the tombstone/verified/accepted-link maps are two different kinds of
 * collaborator: the registry is a mutable, FIFO-capped side collaborator (mutated in place, exactly
 * like `LivenessThrottle`/`PhiAccrualDetector` elsewhere in this codebase), while the tombstone/
 * verified/accepted-link data evolves functionally through {@see ConnectionSupervisorState} — the
 * `BehaviorWithState` state proper.
 *
 * @implements StatefulActorHandler<object, ConnectionSupervisorState>
 */
final class ConnectionSupervisor implements StatefulActorHandler
{
    /**
     * Hard cap on remembered departed-peer path-prefixes — see `ClusterNode`'s prior
     * `MAX_DEPARTED_TOMBSTONES`. Leave frames are unauthenticated, so an unbounded tombstone set is
     * a memory-exhaustion vector; the earliest-inserted prefix is FIFO-evicted at capacity.
     */
    private const int MAX_DEPARTED_TOMBSTONES = 10_000;

    /** Hard cap on tracked handshake-verified endpoint prefixes (SEC-008 check 4) — same discipline. */
    private const int MAX_VERIFIED_ENDPOINT_PREFIXES = 10_000;

    private ?Counter $controlRejected = null;

    /**
     * @param Closure(string): void $forgetThrottle Drops a peer's `LivenessThrottle` window on
     *        link close — injected because the throttle is a `ClusterNode`-owned collaborator this
     *        actor has no other reason to depend on directly.
     * @param Closure(NodeEndpoint): void $evictFromPool Closes and evicts the outbound
     *        `PeerConnectionPool` entry for an endpoint — injected for the same reason.
     */
    public function __construct(
        private readonly MutableEndpointRegistry $endpointRegistry,
        private readonly RoutingSnapshotHolder $snapshotHolder,
        private readonly ActorRef $membershipRef,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly TcpAskRegistry $askRegistry,
        private readonly Closure $forgetThrottle,
        private readonly Closure $evictFromPool,
        private readonly bool $authenticationEnabled,
        private readonly Meter $meter = new NoopMeter(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return Props<object>
     */
    public function props(): Props
    {
        $actor = $this;

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::withState(
            $actor->initialState(),
            /**
             * @param ActorContext<object> $ctx
             * @param ConnectionSupervisorState $state
             * @return BehaviorWithState<object, ConnectionSupervisorState>
             */
            static function (ActorContext $ctx, object $msg, mixed $state) use ($actor): BehaviorWithState {
                return $actor->handle($ctx, $msg, $state);
            },
        );

        return Props::fromBehavior($behavior);
    }

    /**
     * @return ConnectionSupervisorState
     */
    #[Override]
    public function initialState(): mixed
    {
        return new ConnectionSupervisorState();
    }

    #[Override]
    public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        return match (true) {
            $message instanceof RegisterIdentifiedLink => $this->registerIdentifiedLink($message, $state),
            $message instanceof LinkClosed => $this->linkClosed($message, $state),
            $message instanceof RecordTombstone => $this->recordTombstone($message->prefix, $state),
            $message instanceof ClearTombstone => $this->clearTombstone($message->prefix, $state),
            $message instanceof RegisterUnauthenticatedEndpoint => $this->registerUnauthenticatedEndpoint(
                $message,
                $state,
            ),
            $message instanceof EvictPeer => $this->evictPeer($message),
            $message instanceof LinkReport => $this->linkReport($ctx, $state),
            default => BehaviorWithState::same(),
        };
    }

    /**
     * @return BehaviorWithState<object, ConnectionSupervisorState>
     */
    private function registerIdentifiedLink(
        RegisterIdentifiedLink $message,
        ConnectionSupervisorState $state,
    ): BehaviorWithState {
        $prefix = $message->peer->toPathPrefix();

        // Registry write, verified-set mark, and tombstone clear must all complete BEFORE the
        // HandshakeReceived tell below — the load-bearing ordering invariant this actor's own
        // serialized mailbox preserves (see class docblock).
        $this->endpointRegistry->register($message->peer, $message->endpoint);

        $verifiedPrefixes = $this->authenticationEnabled
            ? $this->addToCappedSet($state->verifiedPrefixes, $prefix, self::MAX_VERIFIED_ENDPOINT_PREFIXES)
            : $state->verifiedPrefixes;

        $tombstones = $state->tombstones;
        unset($tombstones[$prefix]);

        // C10 supersede: replace the slot, never close the prior link — the superseded link's own
        // onClose fires {@see LinkClosed}, which is identity-guarded and so cannot clobber this slot.
        // Null on the dialed-outbound path (no accepted-link bookkeeping to do there).
        $acceptedLinks = $state->acceptedLinks;

        if ($message->link !== null) {
            $acceptedLinks[$prefix] = $message->link;
        }

        $newState = new ConnectionSupervisorState(
            tombstones: $tombstones,
            verifiedPrefixes: $verifiedPrefixes,
            acceptedLinks: $acceptedLinks,
            generation: $state->generation + 1,
        );
        $this->publish($newState);

        $this->membershipRef->tell(new HandshakeReceived(
            $message->peer,
            $message->endpoint,
            $message->handshake,
            $message->observedAt,
        ));

        return BehaviorWithState::next($newState);
    }

    /**
     * @return BehaviorWithState<object, ConnectionSupervisorState>
     */
    private function linkClosed(LinkClosed $message, ConnectionSupervisorState $state): BehaviorWithState
    {
        $prefix = $message->peer->toPathPrefix();
        $tombstones = $state->tombstones;
        $acceptedLinks = $state->acceptedLinks;
        $slotChanged = false;

        // Identity-guarded removal: a re-handshake (C10) may already have replaced this slot with a
        // newer link before this close is processed — leave a superseded slot intact.
        if (($acceptedLinks[$prefix] ?? null) === $message->link) {
            unset($acceptedLinks[$prefix]);
            $tombstones = $this->addToCappedSet($tombstones, $prefix, self::MAX_DEPARTED_TOMBSTONES);
            $slotChanged = true;
        }

        ($this->forgetThrottle)($prefix);
        $this->membershipRef->tell(new PeerLinkClosed($message->peer, false));
        $this->safeDispatch(new PeerDisconnected($message->peer));
        // Fail any in-flight asks to this node fast — the reply can't arrive over the dead link.
        $this->askRegistry->failAllForNode($message->peer);

        if (!$slotChanged) {
            return BehaviorWithState::same();
        }

        $newState = new ConnectionSupervisorState(
            tombstones: $tombstones,
            verifiedPrefixes: $state->verifiedPrefixes,
            acceptedLinks: $acceptedLinks,
            generation: $state->generation + 1,
        );
        $this->publish($newState);

        return BehaviorWithState::next($newState);
    }

    /**
     * @return BehaviorWithState<object, ConnectionSupervisorState>
     */
    private function recordTombstone(string $prefix, ConnectionSupervisorState $state): BehaviorWithState
    {
        $tombstones = $this->addToCappedSet($state->tombstones, $prefix, self::MAX_DEPARTED_TOMBSTONES);

        if ($tombstones === $state->tombstones) {
            return BehaviorWithState::same();
        }

        $newState = new ConnectionSupervisorState(
            tombstones: $tombstones,
            verifiedPrefixes: $state->verifiedPrefixes,
            acceptedLinks: $state->acceptedLinks,
            generation: $state->generation + 1,
        );
        $this->publish($newState);

        return BehaviorWithState::next($newState);
    }

    /**
     * @return BehaviorWithState<object, ConnectionSupervisorState>
     */
    private function clearTombstone(string $prefix, ConnectionSupervisorState $state): BehaviorWithState
    {
        if (!isset($state->tombstones[$prefix])) {
            return BehaviorWithState::same();
        }

        $tombstones = $state->tombstones;
        unset($tombstones[$prefix]);

        $newState = new ConnectionSupervisorState(
            tombstones: $tombstones,
            verifiedPrefixes: $state->verifiedPrefixes,
            acceptedLinks: $state->acceptedLinks,
            generation: $state->generation + 1,
        );
        $this->publish($newState);

        return BehaviorWithState::next($newState);
    }

    /**
     * SEC-008 checks 3-4 shared write policy: refuse a CONFLICTING claim about a prefix whose
     * registry entry came from an HMAC-verified Handshake (counted via `$message->rejectLabel`); a
     * matching claim is a silent no-op (steady-state gossip/ack re-announcing the true endpoint);
     * anything else registers. Malformed prefixes are skipped (a later frame may carry them
     * well-formed) — `$message->endpoint` is already validated by the caller.
     *
     * @return BehaviorWithState<object, ConnectionSupervisorState>
     */
    private function registerUnauthenticatedEndpoint(
        RegisterUnauthenticatedEndpoint $message,
        ConnectionSupervisorState $state,
    ): BehaviorWithState {
        $current = $this->endpointRegistry->resolveByPrefix($message->prefix);

        if (isset($state->verifiedPrefixes[$message->prefix]) && $current !== null) {
            if ((string) $current !== (string) $message->endpoint) {
                $this->recordControlRejected($message->rejectLabel);
            }

            return BehaviorWithState::same();
        }

        $addr = self::parseNodeAddress($message->prefix);

        if ($addr === null) {
            return BehaviorWithState::same();
        }

        $this->endpointRegistry->register($addr, $message->endpoint);
        $newState = new ConnectionSupervisorState(
            tombstones: $state->tombstones,
            verifiedPrefixes: $state->verifiedPrefixes,
            acceptedLinks: $state->acceptedLinks,
            generation: $state->generation + 1,
        );
        $this->publish($newState);

        return BehaviorWithState::next($newState);
    }

    /**
     * @return BehaviorWithState<object, ConnectionSupervisorState>
     */
    private function evictPeer(EvictPeer $message): BehaviorWithState
    {
        $endpoint = $this->endpointRegistry->resolveByPrefix($message->peer->toPathPrefix());

        if ($endpoint !== null) {
            ($this->evictFromPool)($endpoint);
        }

        return BehaviorWithState::same();
    }

    /**
     * @return BehaviorWithState<object, ConnectionSupervisorState>
     */
    private function linkReport(ActorContext $ctx, ConnectionSupervisorState $state): BehaviorWithState
    {
        $ctx->reply(new ConnectionReport(
            acceptedPrefixes: array_keys($state->acceptedLinks),
            tombstoneCount: count($state->tombstones),
            verifiedCount: count($state->verifiedPrefixes),
            endpointCount: count($this->endpointRegistry->all()),
            generation: $state->generation,
        ));

        return BehaviorWithState::same();
    }

    private function publish(ConnectionSupervisorState $state): void
    {
        $this->snapshotHolder->publish(new RoutingSnapshot(
            endpoints: $this->endpointRegistry->all(),
            tombstones: $state->tombstones,
            verifiedPrefixes: $state->verifiedPrefixes,
            acceptedLinks: $state->acceptedLinks,
            generation: $state->generation,
        ));
    }

    /**
     * @param array<string, true> $set
     * @return array<string, true>
     */
    private function addToCappedSet(array $set, string $key, int $cap): array
    {
        if (isset($set[$key])) {
            return $set;
        }

        if (count($set) >= $cap) {
            array_shift($set);
        }

        $set[$key] = true;

        return $set;
    }

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
     * Parse a NodeAddress from a path-prefix string: `/cluster/{cluster}/{dc}/{app}/{node}`.
     * Returns null on malformed input. Mirrors `ClusterNode::parseNodeAddress()`.
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
