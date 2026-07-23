<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection\Message;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;

/**
 * @psalm-api
 *
 * A peer link has just been identified by a valid, admitted Handshake (`InboundLinkActor` has
 * already run the parse/auth/SEC-008-reidentify checks — this message carries only the accepted
 * result).
 *
 * {@see \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor} applies, in its own serialized
 * mailbox: the endpoint-registry write, the SEC-008-check-4 verified-prefix mark, the C10 same-identity
 * supersede (replacing the accepted-link slot without closing the prior link), the departed-tombstone
 * clear, a published {@see \Monadial\Nexus\Cluster\Tcp\Connection\RoutingSnapshot}, and finally a tell
 * to the membership actor — in that order, so registration always precedes membership's processing of
 * the resulting `HandshakeReceived` (the load-bearing ordering invariant this actor hop preserves).
 *
 * `$link` is null on the dialed-outbound path (mirrors {@see
 * \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor}'s own per-link `$link` dependency, which
 * only the accepted-inbound spawn populates): the registry write, verified mark, tombstone clear, and
 * membership tell always apply, but the C10 accepted-link-slot write only happens when a link is
 * actually present.
 */
final readonly class RegisterIdentifiedLink
{
    public function __construct(
        public NodeAddress $peer,
        public NodeEndpoint $endpoint,
        public ?PeerLink $link,
        public Handshake $handshake,
        public DateTimeImmutable $observedAt,
    ) {}
}
