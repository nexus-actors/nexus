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
 *     bindEndpoint: new NodeEndpoint(Host::of('0.0.0.0'), Port::of(7355)),
 *     advertiseEndpoint: new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355)),
 *     seeds: [new NodeEndpoint(Host::of('10.0.0.2'), Port::of(7355))],
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
        public Duration $maxNoHeartbeat,
        public float $phiThreshold,
        public Duration $phiMinStdDev,
        public int $phiSampleSize,
        public Duration $gossipInterval,
        public Duration $reconnectInitialBackoff,
        public Duration $reconnectMaxBackoff,
        public Duration $handshakeTimeout,
        public int $maxInboundLinks,
        public int $maxFrameSize,
        public int $minimumMembers,
        public bool $singleNode,
        public ?TlsConfig $tls,
        public ?string $authSecret,
    ) {}

    /**
     * Create a ClusterTopology with sensible defaults.
     *
     * Parameters group as: identity (`clusterName`, `self`) → network (`bindEndpoint`,
     * `advertiseEndpoint`) → seeds → timing (`heartbeatInterval`, `phiThreshold`,
     * `gossipInterval`, reconnect backoffs, `handshakeTimeout`) → limits (`maxInboundLinks`,
     * `minimumMembers`, `singleNode`) → security (`tls`; enable auth via `withAuthSecret()`).
     *
     * @param list<NodeEndpoint> $seeds Seed node endpoints. Must be non-empty unless `$singleNode` is true.
     * @param int $minimumMembers Split-brain floor: minimum reachable (Up) members before this node
     *        will declare any peer Down. 0 (default) disables it. See {@see withMinimumMembers()}.
     *
     * @throws InvalidArgumentException when clusterName is empty, seeds is empty and singleNode is
     *         false, or minimumMembers is negative.
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
        ?Duration $handshakeTimeout = null,
        int $maxInboundLinks = 1_024,
        int $minimumMembers = 0,
        bool $singleNode = false,
        ?TlsConfig $tls = null,
        bool $allowInsecureBind = false,
    ): self {
        if ($clusterName === '') {
            throw new InvalidArgumentException('ClusterTopology clusterName must not be empty.');
        }

        // Fail closed on an exposed insecure bind (SEC-007). A non-loopback bind
        // with no TLS accepts cluster traffic from any reachable host in the
        // clear. Require TLS, or an explicit development override — otherwise
        // point operators at the secure production factory.
        if (!$singleNode && $tls === null && !$allowInsecureBind && !$bindEndpoint->host->isLoopback()) {
            throw new InvalidArgumentException(
                "ClusterTopology binds {$bindEndpoint} without TLS, exposing cluster traffic in the clear. "
                . 'Use ClusterTopology::createProduction() with TLS + an auth secret, pass a TlsConfig via '
                . 'the tls argument, bind to a loopback address, or set allowInsecureBind: true to override '
                . 'in a trusted, network-fenced development environment.',
            );
        }

        if (!$singleNode && count($seeds) === 0) {
            throw new InvalidArgumentException(
                'ClusterTopology seeds must not be empty unless singleNode is true. '
                . 'Pass singleNode: true to start as a standalone node.',
            );
        }

        if ($maxInboundLinks < 1) {
            throw new InvalidArgumentException('ClusterTopology maxInboundLinks must be at least 1.');
        }

        if ($minimumMembers < 0) {
            throw new InvalidArgumentException('ClusterTopology minimumMembers must not be negative.');
        }

        $resolvedHandshakeTimeout = $handshakeTimeout ?? Duration::seconds(10);

        if (!$resolvedHandshakeTimeout->isGreaterThan(Duration::zero())) {
            throw new InvalidArgumentException('ClusterTopology handshakeTimeout must be positive.');
        }

        return new self(
            clusterName: $clusterName,
            self: $self,
            bindEndpoint: $bindEndpoint,
            advertiseEndpoint: $advertiseEndpoint,
            seeds: $seeds,
            heartbeatInterval: $heartbeatInterval ?? Duration::seconds(1),
            maxNoHeartbeat: Duration::seconds(10),
            phiThreshold: $phiThreshold,
            phiMinStdDev: Duration::millis(500),
            phiSampleSize: 200,
            gossipInterval: $gossipInterval ?? Duration::seconds(1),
            reconnectInitialBackoff: $reconnectInitialBackoff ?? Duration::millis(100),
            reconnectMaxBackoff: $reconnectMaxBackoff ?? Duration::seconds(30),
            handshakeTimeout: $resolvedHandshakeTimeout,
            maxInboundLinks: $maxInboundLinks,
            maxFrameSize: 8 * 1024 * 1024,
            minimumMembers: $minimumMembers,
            singleNode: $singleNode,
            tls: $tls,
            authSecret: null,
        );
    }

    /**
     * Secure production factory: TLS and an HMAC auth secret are mandatory, so
     * the resulting topology can never expose cluster traffic in the clear or
     * admit an unauthenticated peer. Prefer this over {@see create()} for any
     * network-exposed deployment.
     *
     * TLS gives transport-level encryption and per-node certificate identity;
     * the auth secret gates the handshake so only nodes holding the shared
     * secret can join. All other timing/limit knobs match {@see create()} and
     * can be tuned afterward with the wither methods.
     *
     * @param list<NodeEndpoint> $seeds Seed node endpoints. Must be non-empty unless `$singleNode` is true.
     * @param string $authSecret Shared HMAC secret every joining node must present. Must be non-empty.
     *
     * @throws InvalidArgumentException when the auth secret is empty, or any {@see create()} invariant fails.
     */
    public static function createProduction(
        string $clusterName,
        NodeAddress $self,
        NodeEndpoint $bindEndpoint,
        NodeEndpoint $advertiseEndpoint,
        array $seeds,
        TlsConfig $tls,
        string $authSecret,
        ?Duration $heartbeatInterval = null,
        float $phiThreshold = 8.0,
        ?Duration $gossipInterval = null,
        ?Duration $reconnectInitialBackoff = null,
        ?Duration $reconnectMaxBackoff = null,
        ?Duration $handshakeTimeout = null,
        int $maxInboundLinks = 1_024,
        int $minimumMembers = 0,
        bool $singleNode = false,
    ): self {
        if ($authSecret === '') {
            throw new InvalidArgumentException('ClusterTopology::createProduction() requires a non-empty authSecret.');
        }

        return self::create(
            clusterName: $clusterName,
            self: $self,
            bindEndpoint: $bindEndpoint,
            advertiseEndpoint: $advertiseEndpoint,
            seeds: $seeds,
            heartbeatInterval: $heartbeatInterval,
            phiThreshold: $phiThreshold,
            gossipInterval: $gossipInterval,
            reconnectInitialBackoff: $reconnectInitialBackoff,
            reconnectMaxBackoff: $reconnectMaxBackoff,
            handshakeTimeout: $handshakeTimeout,
            maxInboundLinks: $maxInboundLinks,
            minimumMembers: $minimumMembers,
            singleNode: $singleNode,
            tls: $tls,
        )->withAuthSecret($authSecret);
    }

    /**
     * Require every joining node to prove it holds the shared cluster secret (HMAC-signed
     * handshake). Without this, `clusterName` is only a label and any reachable peer joins.
     * Combine with TLS for transport-level per-node identity. Pass `null` to disable
     * (the default) — insecure outside a fully trusted, network-fenced segment.
     *
     * @throws InvalidArgumentException when the secret is an empty string.
     */
    public function withAuthSecret(?string $secret): self
    {
        if ($secret === '') {
            throw new InvalidArgumentException(
                'ClusterTopology authSecret must not be an empty string; pass null to disable.',
            );
        }

        return clone($this, ['authSecret' => $secret]);
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

    /**
     * Require at least `$minimumMembers` reachable (Up) members before this node will declare any
     * peer Down. Below the floor the node enters a degraded mode: it stops making new Down
     * decisions (leaving suspected peers Suspect) and emits {@see \Monadial\Nexus\Cluster\Tcp\Membership\ClusterDegraded},
     * so a minority side of a partition cannot independently evict the majority and run as a
     * split-brain singleton. 0 (the default) disables the floor — current behaviour. Set it to a
     * quorum (e.g. N/2 + 1) in production to bound split-brain.
     *
     * @throws InvalidArgumentException when the floor is negative.
     */
    public function withMinimumMembers(int $minimumMembers): self
    {
        if ($minimumMembers < 0) {
            throw new InvalidArgumentException('ClusterTopology minimumMembers must not be negative.');
        }

        return clone($this, ['minimumMembers' => $minimumMembers]);
    }

    /**
     * Cap the maximum decoded frame body size (bytes). A peer that declares a larger
     * frame is rejected before its body is buffered, bounding per-link reassembly
     * memory to roughly this value. The default (8 MiB) is generous for large actor
     * messages; lower it on control-heavy meshes to tighten the memory-DoS surface,
     * but keep it above your largest legitimate message.
     *
     * @throws InvalidArgumentException when the size is not positive.
     */
    public function withMaxFrameSize(int $maxFrameSize): self
    {
        if ($maxFrameSize < 1) {
            throw new InvalidArgumentException('ClusterTopology maxFrameSize must be at least 1 byte.');
        }

        return clone($this, ['maxFrameSize' => $maxFrameSize]);
    }

    public function withFailureDetection(
        ?int $sampleSize = null,
        ?Duration $minStdDev = null,
        ?Duration $maxNoHeartbeat = null,
        ?float $phiThreshold = null,
    ): self {
        return clone($this, [
            'maxNoHeartbeat' => $maxNoHeartbeat ?? $this->maxNoHeartbeat,
            'phiMinStdDev' => $minStdDev ?? $this->phiMinStdDev,
            'phiSampleSize' => $sampleSize ?? $this->phiSampleSize,
            'phiThreshold' => $phiThreshold ?? $this->phiThreshold,
        ]);
    }

    /**
     * Tune the inbound-connection DoS guards: `$handshakeTimeout` is how long an accepted link
     * has to complete a valid handshake before it is closed; `$maxInboundLinks` caps concurrent
     * accepted links.
     *
     * @throws InvalidArgumentException when handshakeTimeout is not positive or maxInboundLinks < 1.
     */
    public function withInboundLimits(?Duration $handshakeTimeout = null, ?int $maxInboundLinks = null): self
    {
        $resolvedHandshakeTimeout = $handshakeTimeout ?? $this->handshakeTimeout;
        $resolvedMaxInboundLinks = $maxInboundLinks ?? $this->maxInboundLinks;

        if (!$resolvedHandshakeTimeout->isGreaterThan(Duration::zero())) {
            throw new InvalidArgumentException('ClusterTopology handshakeTimeout must be positive.');
        }

        if ($resolvedMaxInboundLinks < 1) {
            throw new InvalidArgumentException('ClusterTopology maxInboundLinks must be at least 1.');
        }

        return clone($this, [
            'handshakeTimeout' => $resolvedHandshakeTimeout,
            'maxInboundLinks' => $resolvedMaxInboundLinks,
        ]);
    }
}
