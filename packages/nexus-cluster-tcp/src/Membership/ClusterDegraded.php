<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Emitted while the node is below its configured minimum-members floor
 * ({@see \Monadial\Nexus\Cluster\Tcp\ClusterTopology::withMinimumMembers()}): it has stopped
 * declaring peers Down to avoid a split-brain minority evicting the majority. Carries the current
 * reachable-member count and the required floor so an operator can alert on lost quorum.
 */
final readonly class ClusterDegraded implements MembershipEvent
{
    public function __construct(public int $reachableMembers, public int $requiredMembers) {}
}
