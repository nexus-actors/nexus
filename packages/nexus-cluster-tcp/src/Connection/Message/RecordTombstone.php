<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection\Message;

/**
 * @psalm-api
 *
 * Record `$prefix` as a departed peer (a graceful Leave was processed for it), so lagging gossip
 * cannot resurrect it before the failure detector downs it. FIFO-evicts at
 * {@see \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor}'s tombstone cap when full.
 */
final readonly class RecordTombstone
{
    public function __construct(public string $prefix) {}
}
