<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-api
 *
 * Periodic gossip message exchanged between peers to converge cluster view.
 * `members` carries full membership state for every known node (Up and Suspect)
 * so that gossip propagates incarnation numbers and non-Up status, enabling the
 * receiver to merge accurately even without a direct connection.
 * `registrations` is reserved for C2 (service discovery); always empty in C1.
 *
 * Wire encoding: each member is a plain scalar map so msgpack/Valinor can
 * round-trip the payload without custom normalizers.
 *
 * `status` integers correspond to MemberStatus::rank(): Up = 1, Suspect = 2, Down = 3.
 *
 * @psalm-type GossipMember = array{address: string, endpoint: string, incarnation: int, status: int}
 * @psalm-type RegistrationMap = array<string, string>
 */
#[MessageType('cluster.gossip')]
final readonly class GossipPayload
{
    /**
     * @param list<GossipMember>          $members       Per-member records: address = node path-prefix,
     *                                                   endpoint = host:port, incarnation = monotone counter,
     *                                                   status = MemberStatus::rank() integer.
     * @param list<array<string, string>> $registrations Service registrations (C2); empty in C1.
     */
    public function __construct(public array $members, public array $registrations) {}
}
