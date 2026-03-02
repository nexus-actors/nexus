<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

/**
 * @psalm-api
 *
 * TCP-based inter-node transport for multi-machine cluster communication.
 * Addresses nodes by NodeAddress (cluster/datacenter/application/node).
 * Implementations are deferred to a future remote transport package.
 */
interface ClusterTransport
{
    /**
     * Send serialized data to a target node.
     */
    public function send(NodeAddress $target, string $data): void;

    /**
     * Register a listener for incoming messages.
     *
     * @param callable(string): void $onMessage
     */
    public function listen(callable $onMessage): void;

    /**
     * Close and release resources.
     */
    public function close(): void;
}
