<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;

/**
 * @psalm-api
 *
 * An inbound gossip round decoded off a peer connection. Maps to
 * MembershipService::applyGossip — the enriched member list is merged into the
 * local view.
 */
final readonly class GossipReceived
{
    public function __construct(public NodeAddress $origin, public GossipPayload $gossip) {}
}
