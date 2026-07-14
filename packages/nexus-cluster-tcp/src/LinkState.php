<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Messaging\FrameIngress;

/**
 * @internal
 *
 * Per-link mutable state for the frame pump: the peer identity learned from the link's first
 * Handshake, and the message ingress created once that identity is known. It replaces the
 * by-reference closure captures the inbound and outbound frame handlers each used to carry
 * separately, so both can drive one shared {@see ClusterNode::handleLinkFrame()} state machine
 * instead of duplicating it (and drifting — the two copies had already diverged on which guards
 * they applied). Deliberately mutable: it tracks the evolving state of a single live connection.
 */
final class LinkState
{
    public ?NodeAddress $peerAddr = null;

    public ?FrameIngress $ingress = null;
}
