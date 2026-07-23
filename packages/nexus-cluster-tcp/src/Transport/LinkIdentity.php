<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Transport;

/**
 * @internal
 *
 * Per-accepted-link identification flag, shared between {@see InboundLinkAcceptor}'s out-of-band
 * Slowloris backstop (which reads it at fire time, so a timer that fires in the narrow window
 * between the deadline elapsing and a same-instant identification's cancel being processed does
 * not close a just-identified link) and the `$onIdentified` seam the per-link
 * {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor} invokes at identification (which
 * sets it). Deliberately mutable — it tracks the evolving state of a single live connection, the
 * same role the pre-actorization `LinkState::$peerAddr` null-check played for the acceptor-owned
 * timer this backstop restores.
 */
final class LinkIdentity
{
    public bool $identified = false;
}
