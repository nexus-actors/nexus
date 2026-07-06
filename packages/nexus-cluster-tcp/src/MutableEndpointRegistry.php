<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use Monadial\Nexus\Cluster\NodeAddress;
use Override;

/**
 * @psalm-api
 *
 * Mutable endpoint registry that implements EndpointResolver and allows
 * registering new (NodeAddress, NodeEndpoint) pairs at runtime. Used by the
 * membership service to record endpoints received via gossip.
 *
 * Keys are `NodeAddress::toPathPrefix()` strings for O(1) lookup.
 *
 * @example
 * $registry = new MutableEndpointRegistry();
 * $registry->register($address, new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355)));
 * $endpoint = $registry->resolve($address); // NodeEndpoint
 */
final class MutableEndpointRegistry implements EndpointResolver
{
    /** @var array<string, NodeEndpoint> */
    private array $endpoints = [];

    #[Override]
    public function resolve(NodeAddress $address): ?NodeEndpoint
    {
        return $this->endpoints[$address->toPathPrefix()] ?? null;
    }

    /**
     * Register or overwrite the endpoint for the given node address.
     */
    public function register(NodeAddress $address, NodeEndpoint $endpoint): void
    {
        $this->endpoints[$address->toPathPrefix()] = $endpoint;
    }
}
