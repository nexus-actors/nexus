<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use Monadial\Nexus\Cluster\NodeAddress;
use Override;

/**
 * @psalm-api
 *
 * Immutable endpoint resolver backed by a pre-built map keyed by
 * `NodeAddress::toPathPrefix()`. Use `MutableEndpointRegistry` when gossip
 * needs to add entries at runtime.
 *
 * @example
 * $resolver = new MapEndpointResolver([
 *     $address->toPathPrefix() => new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355)),
 * ]);
 */
final readonly class MapEndpointResolver implements EndpointResolver
{
    /**
     * @param array<string, NodeEndpoint> $endpoints Keyed by NodeAddress::toPathPrefix().
     */
    public function __construct(private array $endpoints) {}

    #[Override]
    public function resolve(NodeAddress $address): ?NodeEndpoint
    {
        return $this->endpoints[$address->toPathPrefix()] ?? null;
    }
}
