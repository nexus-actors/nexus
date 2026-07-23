<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection;

/**
 * @psalm-api
 *
 * Reply to {@see Message\LinkReport}: a read-only snapshot of {@see ConnectionSupervisor}'s
 * bookkeeping, for tests that need to assert on it without reflecting into actor state.
 */
final readonly class ConnectionReport
{
    /**
     * @param list<string> $acceptedPrefixes Path-prefixes with a currently-accepted inbound link.
     */
    public function __construct(
        public array $acceptedPrefixes,
        public int $tombstoneCount,
        public int $verifiedCount,
        public int $endpointCount,
        public int $generation,
    ) {}
}
