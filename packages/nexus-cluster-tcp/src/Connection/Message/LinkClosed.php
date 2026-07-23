<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection\Message;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;

/**
 * @psalm-api
 *
 * An accepted inbound {@see PeerLink} closed. `$link` carries the closing link's identity so
 * {@see \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor} can guard the accepted-link
 * slot removal by object identity: a re-handshake (C10 supersede) may already have replaced the
 * slot with a newer link before this message is processed, in which case the newer slot must be
 * left untouched.
 */
final readonly class LinkClosed
{
    public function __construct(public NodeAddress $peer, public PeerLink $link) {}
}
