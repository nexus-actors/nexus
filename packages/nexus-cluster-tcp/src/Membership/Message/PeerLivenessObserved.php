<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

/**
 * @psalm-api
 *
 * Any inbound frame from a peer (data frame, ping, or pong) that proves the peer
 * is alive. Maps to MembershipService::applyLiveness — feeds the phi detector,
 * adds a newly-seen peer (requires a non-null endpoint), or recovers a Suspect
 * peer to Up. `endpoint` is null when the peer is already known.
 */
final readonly class PeerLivenessObserved
{
    public function __construct(public NodeAddress $peer, public ?NodeEndpoint $endpoint = null) {}
}
