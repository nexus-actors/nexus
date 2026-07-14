<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;

/**
 * @psalm-api
 *
 * Effect: send the enclosed GossipPayload to each of the listed peer
 * path-prefixes (Up and Suspect members alike — a suspected peer must still
 * receive gossip so it can refute its suspicion). The owning actor (C1.6d) resolves each path-prefix to a live
 * TCP endpoint and performs the sends. All targets receive the same payload,
 * capped at three peers per gossip round.
 */
final readonly class SendGossip implements MembershipEffect
{
    /**
     * @param list<string> $targets Node path-prefixes of the members to receive the gossip.
     */
    public function __construct(public array $targets, public GossipPayload $payload) {}
}
