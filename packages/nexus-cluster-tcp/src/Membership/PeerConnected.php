<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

/**
 * @psalm-api
 *
 * Dispatched when a TCP peer link is established — i.e. when the Handshake frame
 * from a peer has been parsed successfully and the link is live. This is a
 * TCP-link lifecycle event, distinct from {@see NodeUp} which is a membership-view
 * transition. A peer may connect and disconnect several times without ever
 * leaving the Up state in the view.
 */
final readonly class PeerConnected
{
    public function __construct(public NodeAddress $peer, public NodeEndpoint $endpoint) {}
}
