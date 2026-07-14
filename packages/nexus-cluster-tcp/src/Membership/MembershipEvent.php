<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Marker for a membership transition emitted by MembershipService and delivered
 * to `onViewChange` listeners. Concrete events: NodeUp, NodeDown, NodeSuspected.
 * The ClusterNode layer (C1.6) fans these out to PSR-14 + observability counters.
 */
interface MembershipEvent {}
