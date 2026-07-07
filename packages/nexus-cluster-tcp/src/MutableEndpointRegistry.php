<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use Monadial\Nexus\Cluster\NodeAddress;
use Override;

use function array_shift;
use function count;
use function max;

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
    /**
     * Hard cap on distinct endpoints retained. Endpoints are learned from unauthenticated
     * gossip, so an unbounded map is a memory-exhaustion vector: a peer can gossip an endless
     * stream of fabricated addresses. When the cap is reached the oldest entry is evicted
     * (insertion-order FIFO). The default comfortably exceeds any realistic cluster size.
     */
    private const int DEFAULT_MAX_ENTRIES = 10_000;

    /** @var array<string, NodeEndpoint> */
    private array $endpoints = [];

    private readonly int $maxEntries;

    public function __construct(int $maxEntries = self::DEFAULT_MAX_ENTRIES)
    {
        $this->maxEntries = max(1, $maxEntries);
    }

    #[Override]
    public function resolve(NodeAddress $address): ?NodeEndpoint
    {
        return $this->endpoints[$address->toPathPrefix()] ?? null;
    }

    /**
     * Register or overwrite the endpoint for the given node address.
     *
     * Registering a new address at capacity evicts the oldest retained entry so the map stays
     * bounded; re-registering an existing address only refreshes its endpoint (no eviction).
     */
    public function register(NodeAddress $address, NodeEndpoint $endpoint): void
    {
        $key = $address->toPathPrefix();

        if (!isset($this->endpoints[$key]) && count($this->endpoints) >= $this->maxEntries) {
            array_shift($this->endpoints);
        }

        $this->endpoints[$key] = $endpoint;
    }

    /**
     * Resolve by raw path-prefix string (e.g. from gossip target lists which carry
     * path-prefix strings rather than NodeAddress objects).
     */
    public function resolveByPrefix(string $pathPrefix): ?NodeEndpoint
    {
        return $this->endpoints[$pathPrefix] ?? null;
    }
}
