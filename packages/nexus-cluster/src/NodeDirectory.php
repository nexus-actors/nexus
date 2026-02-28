<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

/**
 * @psalm-api
 *
 * Maps actor paths to cluster node addresses for multi-machine routing.
 */
interface NodeDirectory
{
    public function register(string $path, NodeAddress $node): void;

    public function lookup(string $path): ?NodeAddress;
}
