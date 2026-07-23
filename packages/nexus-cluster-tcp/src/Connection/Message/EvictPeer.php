<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection\Message;

use Monadial\Nexus\Cluster\NodeAddress;

/**
 * @psalm-api
 *
 * A peer gracefully LEFT the cluster (a verified Leave): evict and close the lazily-created
 * outbound connection to it so its reconnect loop stops. Leave-only — never sent for a
 * phi/timeout suspicion, which may be a false positive that must be allowed to heal.
 */
final readonly class EvictPeer
{
    public function __construct(public NodeAddress $peer) {}
}
