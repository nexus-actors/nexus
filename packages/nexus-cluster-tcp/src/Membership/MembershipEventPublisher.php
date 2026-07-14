<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Publishes the {@see MembershipEvent}s a transition emits. The MembershipActor
 * fans every event out through this seam so that observers (PSR-14 listeners,
 * observability counters) stay decoupled from the actor.
 *
 * C1.7 wires the real implementation onto PSR-14 + metrics; tests use a
 * recording double, and the default in the absence of listeners is a no-op.
 */
interface MembershipEventPublisher
{
    public function publish(MembershipEvent $event): void;
}
