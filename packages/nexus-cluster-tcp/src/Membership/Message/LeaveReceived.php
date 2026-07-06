<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use Monadial\Nexus\Cluster\NodeAddress;

/**
 * @psalm-api
 *
 * A peer announced it is leaving the cluster. Maps to
 * MembershipService::applyLeave — the peer is removed from the view immediately.
 */
final readonly class LeaveReceived
{
    public function __construct(public NodeAddress $origin) {}
}
