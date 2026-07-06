<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use RuntimeException;

/**
 * @psalm-api
 *
 * Internal seam: the richer bidirectional transport abstraction used within
 * nexus-cluster-tcp. Two implementations ship in this package:
 *
 *   - LoopbackMeshTransport — pure-PHP in-process hub, Fiber-compatible, the
 *     dev/test transport (no Swoole required).
 *   - TcpMeshTransport — Swoole Coroutine\Server/Client, the production transport
 *     (introduced in C1.4; requires ext-swoole).
 *
 * The interface is intentionally richer than the public nexus-cluster
 * ClusterTransport contract: it operates at the PeerLink level (one connection
 * per peer) so the membership layer can read individual frame streams.
 */
interface MeshTransport
{
    /**
     * Open a connection to the peer at $endpoint and return the client-side link.
     *
     * For LoopbackMeshTransport: throws RuntimeException immediately if no
     * server is registered in the shared hub for $endpoint.
     *
     * @throws RuntimeException when no server is listening at $endpoint.
     */
    public function connect(NodeEndpoint $endpoint): PeerLink;

    /**
     * Register a server listener on $bind. For each incoming connection the
     * $onAccept callback is invoked with the server-side PeerLink (asynchronously,
     * via the runtime's spawn mechanism).
     *
     * @param callable(PeerLink): void $onAccept
     */
    public function serve(NodeEndpoint $bind, callable $onAccept): void;

    /**
     * Close this transport, unregistering all listeners registered by serve().
     */
    public function close(): void;
}
