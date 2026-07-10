<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * @psalm-api
 *
 * Any inbound frame from a peer (data frame, ping, or pong) that proves the peer
 * is alive. Maps to MembershipService::applyLiveness — feeds the phi detector,
 * adds a newly-seen peer (requires a non-null endpoint), or recovers a Suspect
 * peer to Up. `endpoint` is null when the peer is already known.
 *
 * `observedAt` is the socket-receive time stamped at frame ingress (in the recv
 * coroutine), NOT the time the membership actor processes this message. Feeding
 * the phi detector the ingress time keeps failure detection immune to local
 * scheduler contention under data-plane load.
 */
final readonly class PeerLivenessObserved implements UntracedMessage
{
    public function __construct(
        public NodeAddress $peer,
        public ?NodeEndpoint $endpoint,
        public DateTimeImmutable $observedAt,
    ) {}
}
