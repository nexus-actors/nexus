<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Chooses which peers receive a gossip round. Abstracted behind an interface so
 * MembershipService gossip fan-out is deterministic under test (inject a fake)
 * while production uses uniform random selection.
 */
interface PeerSelector
{
    /**
     * Pick up to `$count` peers from `$peers`. Returns fewer only when `$peers`
     * is smaller than `$count`. Never returns duplicates.
     *
     * @param list<string> $peers
     *
     * @return list<string>
     */
    public function select(array $peers, int $count): array;
}
