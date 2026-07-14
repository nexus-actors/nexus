<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\NodeAddress;
use Override;

use function array_key_exists;

/**
 * @psalm-api
 *
 * Decorates a {@see MembershipEventPublisher} to maintain a set of peers currently considered
 * departed (Down): a peer is added on {@see NodeDown} and removed again on {@see NodeUp}. This
 * powers {@see \Monadial\Nexus\Cluster\Tcp\Messaging\ClusterRef::isAlive()} without any blocking
 * call — the ref factory is handed an {@see self::isAlive()} probe that consults this in-memory set.
 *
 * Thread-confinement: the set is only ever mutated on the membership actor's own event-publishing
 * path (this decorator wraps the actor's publisher) and only ever read from the recv/membership
 * event loop, so no locking is required — matching the package convention for the mutable
 * PhiAccrualDetector and liveness collaborators.
 */
final class DepartedPeerTracker implements MembershipEventPublisher
{
    /** @var array<string, true> Path-prefix keys of peers currently believed Down. */
    private array $departed = [];

    public function __construct(private readonly MembershipEventPublisher $inner) {}

    #[Override]
    public function publish(MembershipEvent $event): void
    {
        if ($event instanceof NodeDown) {
            $this->departed[$event->node->toPathPrefix()] = true;
        } elseif ($event instanceof NodeUp) {
            unset($this->departed[$event->node->toPathPrefix()]);
        }

        $this->inner->publish($event);
    }

    /**
     * A peer is alive unless it is currently in the departed (Down) set. An unknown peer — one we
     * have never heard a Down for — is treated as alive (optimistic, matching the location-transparent
     * default that a freshly-minted ref is usable until proven otherwise).
     */
    public function isAlive(NodeAddress $node): bool
    {
        return !array_key_exists($node->toPathPrefix(), $this->departed);
    }
}
