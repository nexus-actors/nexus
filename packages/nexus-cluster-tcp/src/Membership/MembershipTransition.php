<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;

/**
 * @psalm-api
 *
 * Result of a pure membership state transition. Carries the complete new state
 * the actor must adopt, the MembershipEvents to broadcast, and the
 * MembershipEffects to execute.
 *
 * Separation of concerns:
 *   - newView / newSuspectSince / newSelfIncarnation → actor replaces its state.
 *   - events   → actor fans out to PSR-14 / observability counters.
 *   - effects  → actor executes I/O (TCP sends, acks).
 *
 * `newSuspectSince` records when each peer entered Suspect status, keyed by
 * NodeAddress path-prefix. The actor must track this across transitions so that
 * applyTick can evaluate the give-up window.
 */
final readonly class MembershipTransition
{
    /**
     * @param list<MembershipEvent>            $events
     * @param list<MembershipEffect>           $effects
     * @param array<string, DateTimeImmutable> $newSuspectSince Suspect-start timestamps keyed by NodeAddress path-prefix.
     */
    public function __construct(
        public ClusterView $newView,
        public array $events,
        public array $effects,
        public array $newSuspectSince,
        public int $newSelfIncarnation,
    ) {}
}
