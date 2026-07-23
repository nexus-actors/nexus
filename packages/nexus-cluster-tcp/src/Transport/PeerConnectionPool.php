<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Transport;

use Closure;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;

/**
 * @psalm-api
 *
 * Pool of lazily-created outbound {@see PeerConnection}s, deduped by `(string) NodeEndpoint`.
 * A `PeerConnection`'s constructor dials immediately, so this dedup is what prevents a duplicate
 * reconnect loop for the same peer — a caller that asks twice for the same endpoint must get back
 * the SAME connection, not a second one racing its own backoff.
 *
 * CRITICAL asymmetry: {@see dial()} does not wire `onFrame` on the connection it returns. Seed
 * connections get their frame pump wired by the caller right after dialing (so the pump is set up
 * exactly once, by the code that knows this is a seed); a lazy send via {@see dial()} from the
 * per-message hot path wires nothing (send-only) and never observes inbound frames on that
 * connection — this is a documented hazard, not an oversight.
 */
final class PeerConnectionPool
{
    /** @var array<string, PeerConnection> Outbound connections keyed by (string) NodeEndpoint */
    private array $conns = [];

    /**
     * @param Closure(): Frame $preamble Handshake preamble factory, forwarded to every
     *        {@see PeerConnection} this pool creates — see {@see ClusterNode::handshakePreamble()}.
     */
    public function __construct(
        private readonly MeshTransport $transport,
        private readonly Runtime $runtime,
        private readonly Duration $initialBackoff,
        private readonly Duration $maxBackoff,
        private readonly Closure $preamble,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Return the connection for `$endpoint`, dialing (constructing) one on first use. Dedup is by
     * `(string) $endpoint`: a second `dial()` for the same endpoint returns the same instance
     * rather than starting a second reconnect loop.
     */
    public function dial(NodeEndpoint $endpoint): PeerConnection
    {
        $key = (string) $endpoint;

        if (!isset($this->conns[$key])) {
            $this->conns[$key] = new PeerConnection(
                $endpoint,
                $this->transport,
                $this->runtime,
                $this->initialBackoff,
                $this->maxBackoff,
                logger: $this->logger,
                preamble: $this->preamble,
            );
        }

        return $this->conns[$key];
    }

    /**
     * Return the connection already dialed for `$endpoint`, or null. Never dials — pure lookup,
     * so a caller can distinguish "already dialed" from "not yet dialed" before calling
     * {@see dial()} (e.g. to decide whether its frame pump still needs wiring).
     */
    public function existing(NodeEndpoint $endpoint): ?PeerConnection
    {
        return $this->conns[(string) $endpoint] ?? null;
    }

    /**
     * Close and remove the connection for `$endpoint` so its reconnect loop stops. No-op if no
     * connection has been dialed for this endpoint.
     */
    public function evict(NodeEndpoint $endpoint): void
    {
        $key = (string) $endpoint;
        $conn = $this->conns[$key] ?? null;

        if ($conn === null) {
            return;
        }

        unset($this->conns[$key]);
        $conn->close();
    }

    /**
     * Close every live connection and clear the pool. Used on node shutdown, after the Leave
     * broadcast via {@see each()} has already reached every connection.
     */
    public function closeAll(): void
    {
        foreach ($this->conns as $conn) {
            $conn->close();
        }

        $this->conns = [];
    }

    /**
     * Invoke `$fn` synchronously for every currently-live connection — used to broadcast the
     * shutdown Leave frame to every peer before the connections are closed.
     *
     * @param Closure(PeerConnection): void $fn
     */
    public function each(Closure $fn): void
    {
        foreach ($this->conns as $conn) {
            $fn($conn);
        }
    }

    /**
     * Number of currently-live outbound connections.
     */
    public function count(): int
    {
        return count($this->conns);
    }
}
