<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

/**
 * @psalm-api
 *
 * A node became reachable — either newly joined or recovered from Suspect.
 */
final readonly class NodeUp implements MembershipEvent
{
    public function __construct(public NodeAddress $node, public NodeEndpoint $endpoint) {}
}
