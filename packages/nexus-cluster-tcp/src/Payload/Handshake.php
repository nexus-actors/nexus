<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-api
 *
 * Sent by the connecting node immediately after TCP connection to identify
 * itself and assert cluster membership. The `node` map carries the four
 * NodeAddress fields (cluster/datacenter/application/node) as plain strings.
 * `advertise` is the host:port peers should use to connect back.
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
    ) {}
}
