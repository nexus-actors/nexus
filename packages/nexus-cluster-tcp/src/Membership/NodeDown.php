<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\NodeAddress;

/**
 * @psalm-api
 *
 * A node was declared dead — reconnect give-up expired or it sent a Leave.
 */
final readonly class NodeDown implements MembershipEvent
{
    public function __construct(public NodeAddress $node) {}
}
