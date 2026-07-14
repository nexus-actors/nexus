<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\NodeAddress;

/**
 * @psalm-api
 *
 * Dispatched when a TCP peer link closes — either due to peer death or an
 * intentional local disconnect. This is a TCP-link lifecycle event, distinct from
 * {@see NodeDown} (a membership-view transition): a peer may disconnect and
 * reconnect without ever becoming Down in the cluster view.
 */
final readonly class PeerDisconnected
{
    public function __construct(public NodeAddress $peer) {}
}
