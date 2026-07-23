<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Transport;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;

/**
 * @psalm-api
 *
 * One inbound {@see Frame}, posted to an {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor}
 * by the pump that owns the underlying transport link (the accepted-inbound acceptor, or the
 * outbound seed's connection pump) — never sent by user code. Lives in `Transport/` (not
 * `Connection/Message/`, alongside the actor it targets) because it is produced by transport-layer
 * code (`InboundLinkAcceptor`, `ClusterNode::dialSeed()`) — the dependency-boundary rule
 * (`ClusterTcpTransport` may not depend on `ClusterTcpConnection`) only allows the reverse.
 *
 * `$observedAt` and `$monotonicNs` are stamped by the pump at the moment the frame actually
 * arrives off the transport, BEFORE this message is offered to the actor's mailbox — not
 * recomputed when the actor later dequeues it. This preserves the pre-actorization timing
 * invariant (C3): the actor's own mailbox is a new potential source of processing lag that did
 * not exist when frame handling ran synchronously off the transport callback, and re-deriving
 * "now" at dequeue time would let that lag corrupt both the phi-detector arrival time
 * ({@see \Monadial\Nexus\Cluster\Tcp\Membership\Message\HandshakeReceived}/
 * {@see \Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLivenessObserved}) and the
 * {@see \Monadial\Nexus\Cluster\Tcp\Membership\LivenessThrottle} interval math. `$monotonicNs` is
 * the `hrtime(true)` reading the throttle needs; `$observedAt` is the wall-clock reading fed to
 * membership.
 */
final readonly class LinkFrame
{
    public function __construct(public Frame $frame, public DateTimeImmutable $observedAt, public int $monotonicNs) {}
}
