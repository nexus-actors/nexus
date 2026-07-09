<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-api
 *
 * Sent by the connecting node immediately after TCP connection to identify
 * itself and assert cluster membership. The `node` map carries the four
 * NodeAddress fields (cluster/datacenter/application/node) as plain strings.
 * `advertise` is the host:port peers should use to connect back.
 *
 * When a cluster secret is configured, `nonce`/`issuedAt`/`mac` carry the
 * {@see \Monadial\Nexus\Cluster\Tcp\Membership\HandshakeAuthenticator} signature
 * proving the sender holds the secret; they are null on an unauthenticated cluster
 * (and remain wire-compatible with peers that never send them).
 *
 * @psalm-type NodeMap = array<string, string>
 */
#[MessageType('cluster.handshake')]
final readonly class Handshake
{
    /**
     * @param array<string, string> $node NodeAddress fields: cluster/datacenter/application/node.
     */
    public function __construct(
        public string $clusterName,
        public array $node,
        public string $advertise,
        public int $protocolVersion = 1,
        public ?string $nonce = null,
        public ?int $issuedAt = null,
        public ?string $mac = null,
    ) {}

    /**
     * Build a Handshake payload announcing the given topology's identity and advertise endpoint.
     */
    public static function forSelf(ClusterTopology $topology): self
    {
        $self = $topology->self;

        return new self(
            clusterName: $topology->clusterName,
            node: [
                'application' => $self->application,
                'cluster' => $self->cluster,
                'datacenter' => $self->datacenter,
                'node' => $self->node,
            ],
            advertise: (string) $topology->advertiseEndpoint,
        );
    }
}
