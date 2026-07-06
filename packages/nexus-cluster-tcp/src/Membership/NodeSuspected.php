<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\NodeAddress;

/**
 * @psalm-api
 *
 * A node became provisionally unreachable. `reason` distinguishes a phi-accrual
 * timeout from an unexpected connection close.
 */
final readonly class NodeSuspected implements MembershipEvent
{
    public function __construct(public NodeAddress $node, public SuspicionReason $reason) {}
}
