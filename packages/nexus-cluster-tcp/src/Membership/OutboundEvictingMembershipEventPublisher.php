<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Override;

/**
 * @psalm-api
 *
 * Decorates a {@see MembershipEventPublisher}: when a node is declared {@see NodeDown}, evicts and
 * closes its lazily-created outbound connection before forwarding the event. A phi-accrual Down (or
 * a give-up on a half-open peer) leaves the outbound {@see \Monadial\Nexus\Cluster\Tcp\PeerConnection}
 * reconnect loop running forever against a dead endpoint — a timer + SYN-traffic leak proportional
 * to the lifetime count of departed peers. Evicting on the authoritative Down decision stops it.
 *
 * A still-live peer that merely flapped is re-dialed lazily the next time a frame is routed to it,
 * so legitimate reconnection is preserved.
 */
final readonly class OutboundEvictingMembershipEventPublisher implements MembershipEventPublisher
{
    /** @param Closure(NodeAddress): void $evict */
    public function __construct(private MembershipEventPublisher $inner, private Closure $evict) {}

    #[Override]
    public function publish(MembershipEvent $event): void
    {
        if ($event instanceof NodeDown) {
            ($this->evict)($event->node);
        }

        $this->inner->publish($event);
    }
}
