<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use Monadial\Nexus\Cluster\NodeAddress;

/**
 * @psalm-api
 *
 * Resolves a cluster node identity (NodeAddress) to its network endpoint
 * (NodeEndpoint). The map grows dynamically as gossip delivers
 * (NodeAddress, NodeEndpoint) pairs from peer nodes.
 *
 * @example
 * $resolver = new MapEndpointResolver([
 *     $address->toPathPrefix() => new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355)),
 * ]);
 * $endpoint = $resolver->resolve($address); // NodeEndpoint|null
 */
interface EndpointResolver
{
    /**
     * Resolve the network endpoint for the given node address.
     *
     * Returns null when no endpoint is registered for the address.
     */
    public function resolve(NodeAddress $address): ?NodeEndpoint;
}
