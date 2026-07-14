<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;

/**
 * @psalm-api
 *
 * The MembershipActor's evolving state — the pure, replaceable portion of the
 * membership machine (everything the C1.5 service kept mutable except the
 * PhiAccrualDetector, which stays a thread-confined actor collaborator).
 *
 * The actor replaces this wholesale after each transition via
 * {@see BehaviorWithState::next()}:
 *   - $view            — the current ClusterView snapshot.
 *   - $suspectSince    — when each peer entered Suspect, keyed by path-prefix;
 *                        drives the applyTick give-up window.
 *   - $selfIncarnation — local incarnation, bumped by applyRejoin.
 */
final readonly class MembershipState
{
    /**
     * @param array<string, DateTimeImmutable> $suspectSince Suspect-start timestamps keyed by NodeAddress path-prefix.
     */
    public function __construct(public ClusterView $view, public array $suspectSince, public int $selfIncarnation) {}

    /**
     * Adopt the new state carried by a completed transition.
     */
    public static function fromTransition(MembershipTransition $transition): self
    {
        return new self($transition->newView, $transition->newSuspectSince, $transition->newSelfIncarnation);
    }
}
