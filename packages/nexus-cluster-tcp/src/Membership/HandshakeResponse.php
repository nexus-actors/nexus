<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\NodeAddress;

/**
 * @psalm-api
 *
 * Effect: send a HandshakeAck to the connecting peer. `peer` identifies which node
 * to send to. When `accepted` is false, `reason` explains the rejection and `view`
 * is empty. When accepted, `view` carries a node path-prefix → host:port snapshot
 * of the local view after merge.
 */
final readonly class HandshakeResponse implements MembershipEffect
{
    /**
     * @param array<string, string> $view Local view snapshot (empty on rejection).
     */
    public function __construct(
        public NodeAddress $peer,
        public bool $accepted,
        public ?string $reason,
        public array $view,
    ) {}
}
