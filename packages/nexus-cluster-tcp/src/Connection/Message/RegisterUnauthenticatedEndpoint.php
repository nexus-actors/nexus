<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection\Message;

use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

/**
 * @psalm-api
 *
 * Register an endpoint learned from an UNAUTHENTICATED per-entry claim — a HandshakeAck view entry
 * or a gossip member entry — the single shared write policy behind SEC-008 checks 3-4. `$rejectLabel`
 * is the `nexus.cluster.control.rejected` `check` attribute value {@see
 * \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor} records when it refuses the write (a
 * CONFLICTING claim about a prefix whose registry entry came from an HMAC-verified Handshake).
 */
final readonly class RegisterUnauthenticatedEndpoint
{
    public function __construct(public string $prefix, public NodeEndpoint $endpoint, public string $rejectLabel,) {}
}
