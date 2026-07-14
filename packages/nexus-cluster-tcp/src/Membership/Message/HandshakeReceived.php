<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;

/**
 * @psalm-api
 *
 * An inbound Handshake decoded off a peer connection. Maps to
 * MembershipService::applyHandshake — the actor supplies the connecting peer's
 * identity, advertised endpoint, and the handshake payload (cluster name +
 * protocol version).
 *
 * `$observedAt` is the socket-ingress timestamp captured when the frame was
 * parsed (mirroring {@see PeerLivenessObserved::observedAt}), so the phi
 * failure detector is fed the arrival time, not the actor's processing time.
 */
final readonly class HandshakeReceived
{
    public function __construct(
        public NodeAddress $origin,
        public NodeEndpoint $endpoint,
        public Handshake $handshake,
        public DateTimeImmutable $observedAt,
    ) {}
}
