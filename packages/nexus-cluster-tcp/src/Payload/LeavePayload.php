<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-api
 *
 * Graceful departure notice. A node sends this before closing its connections
 * so peers can remove it from their view immediately rather than waiting for
 * phi-accrual failure detection. `node` is the NodeAddress path-prefix of the
 * departing node.
 *
 * When a cluster secret is configured, `nonce`/`issuedAt`/`mac` carry the
 * {@see \Monadial\Nexus\Cluster\Tcp\Membership\HandshakeAuthenticator} self-attestation signature
 * (SEC-008 check 1) proving the leaving node itself produced this notice — the mac is leaver-bound
 * and link-independent, so a relayed Leave still verifies unchanged on every hop. They are null on
 * an unauthenticated cluster (and remain wire-compatible with peers that never send them).
 */
#[MessageType('cluster.leave')]
final readonly class LeavePayload
{
    public function __construct(
        public string $node,
        public ?string $nonce = null,
        public ?int $issuedAt = null,
        public ?string $mac = null,
    ) {}
}
