<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use Monadial\Nexus\Cluster\NodeAddress;

/**
 * @psalm-api
 *
 * A peer's TCP link closed. Maps to MembershipService::applyLinkClosed — an
 * unexpected close (`intentional: false`) moves an Up peer to Suspect with
 * reason Connection; an intentional local close (`intentional: true`) is a no-op.
 */
final readonly class PeerLinkClosed
{
    public function __construct(public NodeAddress $peer, public bool $intentional) {}
}
