<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection;

use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;

/**
 * @psalm-api
 *
 * Immutable, point-in-time view of {@see ConnectionSupervisor}'s routing state, published to a
 * {@see RoutingSnapshotHolder} after every mutation. Readers on the hot path (egress via
 * `ClusterNode::sendByPrefix()`, and the SEC-008 admission checks in `ClusterNode::handleLinkFrame()`)
 * read the holder's current snapshot directly instead of going through the supervisor's mailbox —
 * lock-free, but necessarily lagging the supervisor's own state by however long its mailbox takes to
 * drain. Callers that cannot tolerate the lag do not exist yet: `sendByPrefix()`'s accepted-link
 * fallback already tolerates the equivalent staleness a pre-actorization synchronous read had, and
 * the SEC-008 checks tolerate it for the same reason a same-tick race was already possible pre-actorization.
 *
 * `$generation` increments on every published snapshot — a strictly-increasing counter a caller can
 * use to detect whether it observed a stale read across two calls (not currently consumed by any
 * reader, but cheap to carry and exactly the kind of seam {@see ConnectionSupervisor::LinkReport}-style
 * introspection wants).
 */
final readonly class RoutingSnapshot
{
    /**
     * @param array<string, NodeEndpoint> $endpoints Node path-prefix -> resolved network endpoint.
     * @param array<string, true> $tombstones Departed-peer path-prefixes (graceful Leave or a
     *        definitively closed link) — see {@see ConnectionSupervisor}'s `$departedTombstones`.
     * @param array<string, true> $verifiedPrefixes Path-prefixes whose endpoint came from an
     *        HMAC-verified Handshake (SEC-008 check 4).
     * @param array<string, PeerLink> $acceptedLinks Accepted inbound links, keyed by path-prefix.
     */
    public function __construct(
        public array $endpoints,
        public array $tombstones,
        public array $verifiedPrefixes,
        public array $acceptedLinks,
        public int $generation,
    ) {}

    /**
     * The initial snapshot published before any link has ever been admitted.
     */
    public static function empty(): self
    {
        return new self([], [], [], [], 0);
    }
}
