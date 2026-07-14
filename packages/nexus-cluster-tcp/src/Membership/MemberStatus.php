<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Lifecycle status of a cluster member as tracked by the local MembershipService.
 *
 *   - Up      — reachable; heartbeats arriving and phi below threshold.
 *   - Suspect — provisionally unreachable (phi exceeded threshold or link closed
 *               unexpectedly); still a candidate for recovery.
 *   - Down    — declared dead after the reconnect give-up window; removed on Leave.
 *
 * Merge tie-breaking uses `rank()`: a worse (higher-rank) status wins when two
 * records share the same incarnation.
 */
enum MemberStatus
{
    case Down;
    case Suspect;
    case Up;

    /**
     * Severity ordering used for merge tie-breaking: Down (worst) > Suspect > Up.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Down => 3,
            self::Suspect => 2,
            self::Up => 1,
        };
    }
}
