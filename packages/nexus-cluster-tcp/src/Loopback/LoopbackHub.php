<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Loopback;

use Closure;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\PeerLink;

/**
 * @psalm-api
 *
 * Shared in-process registry that maps endpoint strings to their active
 * serve() listeners. Multiple LoopbackMeshTransport instances share one hub
 * so that node A can connect() to node B's serve() listener.
 *
 * The hub is the single object that crosses transport boundaries. Inject one
 * instance into every transport that forms a loopback cluster:
 *
 *   $hub  = new LoopbackHub();
 *   $nodeA = new LoopbackMeshTransport($hub, $runtime);
 *   $nodeB = new LoopbackMeshTransport($hub, $runtime);
 *
 * Design note: per-transport-instance state cannot work for multi-node
 * loopback because each transport only knows about its own listeners; the hub
 * is the rendezvous point that makes cross-transport connect() possible.
 */
final class LoopbackHub
{
    /** @var array<string, Closure(PeerLink): void> */
    private array $listeners = [];

    /**
     * Register $onAccept as the listener for incoming connections at $bind.
     * Overwrites any previous listener registered for the same endpoint.
     *
     * @param callable(PeerLink): void $onAccept
     */
    public function register(NodeEndpoint $bind, callable $onAccept): void
    {
        $this->listeners[(string) $bind] = $onAccept(...);
    }

    /**
     * Remove the listener for $bind. No-op if no listener is registered.
     */
    public function unregister(NodeEndpoint $bind): void
    {
        unset($this->listeners[(string) $bind]);
    }

    /**
     * Return the registered listener for $endpoint, or null if none.
     *
     * @return (Closure(PeerLink): void)|null
     */
    public function findListener(NodeEndpoint $endpoint): ?Closure
    {
        return $this->listeners[(string) $endpoint] ?? null;
    }
}
