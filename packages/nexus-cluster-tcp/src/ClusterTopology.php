<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Runtime\Duration;

use function count;

/**
 * @psalm-api
 *
 * Immutable configuration value object for a cluster node. Carries all knobs
 * needed to bootstrap ClusterNode: identity, network endpoints, seed list,
 * timing parameters, and optional TLS.
 *
 * Bind vs. advertise: `bindEndpoint` is the local address the TCP server binds
 * to (e.g. `0.0.0.0:7355`); `advertiseEndpoint` is what peers use to connect
 * (e.g. the pod IP in Kubernetes). They may be the same on bare-metal.
 *
 * @example
 * $topology = ClusterTopology::create(
 *     clusterName: 'production',
 *     self: new NodeAddress('prod', 'eu', 'payments', 'node-1'),
 *     bindEndpoint: new NodeEndpoint('0.0.0.0', 7355),
 *     advertiseEndpoint: new NodeEndpoint('10.0.0.1', 7355),
 *     seeds: [new NodeEndpoint('10.0.0.2', 7355)],
 * );
 */
final readonly class ClusterTopology
{
    /**
     * @param list<NodeEndpoint> $seeds
     */
    private function __construct(
        public string $clusterName,
        public NodeAddress $self,
        public NodeEndpoint $bindEndpoint,
        public NodeEndpoint $advertiseEndpoint,
        public array $seeds,
        public Duration $heartbeatInterval,
        public float $phiThreshold,
        public Duration $gossipInterval,
        public Duration $reconnectInitialBackoff,
        public Duration $reconnectMaxBackoff,
        public bool $singleNode,
        public ?TlsConfig $tls,
    ) {}

    /**
     * Create a ClusterTopology with sensible defaults.
     *
     * @param list<NodeEndpoint> $seeds Seed node endpoints. Must be non-empty unless `$singleNode` is true.
     *
     * @throws InvalidArgumentException when clusterName is empty or seeds is empty and singleNode is false.
     */
    public static function create(
        string $clusterName,
        NodeAddress $self,
        NodeEndpoint $bindEndpoint,
        NodeEndpoint $advertiseEndpoint,
        array $seeds,
        ?Duration $heartbeatInterval = null,
        float $phiThreshold = 8.0,
        ?Duration $gossipInterval = null,
        ?Duration $reconnectInitialBackoff = null,
        ?Duration $reconnectMaxBackoff = null,
        bool $singleNode = false,
        ?TlsConfig $tls = null,
    ): self {
        if ($clusterName === '') {
            throw new InvalidArgumentException('ClusterTopology clusterName must not be empty.');
        }

        if (!$singleNode && count($seeds) === 0) {
            throw new InvalidArgumentException(
                'ClusterTopology seeds must not be empty unless singleNode is true. '
                . 'Pass singleNode: true to start as a standalone node.',
            );
        }

        return new self(
            clusterName: $clusterName,
            self: $self,
            bindEndpoint: $bindEndpoint,
            advertiseEndpoint: $advertiseEndpoint,
            seeds: $seeds,
            heartbeatInterval: $heartbeatInterval ?? Duration::seconds(1),
            phiThreshold: $phiThreshold,
            gossipInterval: $gossipInterval ?? Duration::seconds(1),
            reconnectInitialBackoff: $reconnectInitialBackoff ?? Duration::millis(100),
            reconnectMaxBackoff: $reconnectMaxBackoff ?? Duration::seconds(30),
            singleNode: $singleNode,
            tls: $tls,
        );
    }

    public function withHeartbeatInterval(Duration $heartbeatInterval): self
    {
        return clone($this, ['heartbeatInterval' => $heartbeatInterval]);
    }

    public function withGossipInterval(Duration $gossipInterval): self
    {
        return clone($this, ['gossipInterval' => $gossipInterval]);
    }

    public function withPhiThreshold(float $phiThreshold): self
    {
        return clone($this, ['phiThreshold' => $phiThreshold]);
    }

    public function withReconnectBackoff(Duration $initialBackoff, Duration $maxBackoff): self
    {
        return clone($this, [
            'reconnectInitialBackoff' => $initialBackoff,
            'reconnectMaxBackoff' => $maxBackoff,
        ]);
    }

    public function withTls(?TlsConfig $tls): self
    {
        return clone($this, ['tls' => $tls]);
    }
}
