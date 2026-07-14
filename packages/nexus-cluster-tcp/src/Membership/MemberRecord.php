<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

/**
 * @psalm-api
 *
 * Immutable snapshot of one cluster member's identity, network endpoint, and
 * liveness. `incarnation` monotonically increases each time a node rejoins,
 * letting the gossip layer supersede stale entries. `status` + `lastSeen`
 * capture the local view's most recent judgement about the member.
 */
final readonly class MemberRecord
{
    public function __construct(
        public NodeAddress $address,
        public NodeEndpoint $endpoint,
        public int $incarnation,
        public MemberStatus $status,
        public DateTimeImmutable $lastSeen,
    ) {}

    public function withStatus(MemberStatus $status, DateTimeImmutable $lastSeen): self
    {
        return new self($this->address, $this->endpoint, $this->incarnation, $status, $lastSeen);
    }
}
