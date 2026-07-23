<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection;

use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;

/**
 * @internal
 *
 * {@see ConnectionSupervisor}'s functionally-evolved actor state: departed-peer tombstones,
 * SEC-008-verified endpoint prefixes, accepted inbound links, and a monotonically increasing
 * generation counter. Endpoints are deliberately NOT part of this state — they live in the
 * injected {@see \Monadial\Nexus\Cluster\Tcp\MutableEndpointRegistry} collaborator, mutated
 * in place exactly like `LivenessThrottle`/`PhiAccrualDetector` are constructor-injected,
 * thread-confined-by-single-actor-access collaborators elsewhere in this codebase.
 */
final readonly class ConnectionSupervisorState
{
    /**
     * @param array<string, true> $tombstones
     * @param array<string, true> $verifiedPrefixes
     * @param array<string, PeerLink> $acceptedLinks
     */
    public function __construct(
        public array $tombstones = [],
        public array $verifiedPrefixes = [],
        public array $acceptedLinks = [],
        public int $generation = 0,
    ) {}
}
