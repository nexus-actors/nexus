<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\TlsConfig;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClusterTopology::class)]
final class ClusterTopologyTest extends TestCase
{
    private NodeAddress $self;

    private NodeEndpoint $bindEndpoint;

    private NodeEndpoint $advertiseEndpoint;

    /** @var list<NodeEndpoint> */
    private array $seeds;

    #[Test]
    public function factoryCreatesWithDefaults(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        self::assertSame('production', $topology->clusterName);
        self::assertSame($this->self, $topology->self);
        self::assertSame($this->bindEndpoint, $topology->bindEndpoint);
        self::assertSame($this->advertiseEndpoint, $topology->advertiseEndpoint);
        self::assertSame($this->seeds, $topology->seeds);
        self::assertEquals(Duration::seconds(1), $topology->heartbeatInterval);
        self::assertEquals(Duration::seconds(10), $topology->maxNoHeartbeat);
        self::assertSame(8.0, $topology->phiThreshold);
        self::assertEquals(Duration::millis(500), $topology->phiMinStdDev);
        self::assertSame(200, $topology->phiSampleSize);
        self::assertEquals(Duration::seconds(1), $topology->gossipInterval);
        self::assertEquals(Duration::seconds(10), $topology->handshakeTimeout);
        self::assertSame(1_024, $topology->maxInboundLinks);
        self::assertSame(8 * 1024 * 1024, $topology->maxFrameSize);
        self::assertSame(0, $topology->minimumMembers);
        self::assertFalse($topology->singleNode);
        self::assertNull($topology->tls);
        self::assertNull($topology->authSecret);
    }

    #[Test]
    public function withMaxFrameSizeReturnsNewInstanceWithTheCap(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        $tightened = $topology->withMaxFrameSize(1024 * 1024);

        self::assertSame(8 * 1024 * 1024, $topology->maxFrameSize, 'original is unchanged');
        self::assertSame(1024 * 1024, $tightened->maxFrameSize);
    }

    #[Test]
    public function withMinimumMembersSetsTheQuorumFloorAndRejectsNegative(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        self::assertSame(3, $topology->withMinimumMembers(3)->minimumMembers);
        self::assertSame(0, $topology->minimumMembers, 'original is unchanged');

        $this->expectException(InvalidArgumentException::class);
        $topology->withMinimumMembers(-1);
    }

    #[Test]
    public function factoryAcceptsMinimumMembersParam(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
            minimumMembers: 3,
        );

        self::assertSame(3, $topology->minimumMembers);
    }

    #[Test]
    public function factoryRejectsNegativeMinimumMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minimumMembers');

        ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
            minimumMembers: -1,
        );
    }

    #[Test]
    public function withMaxFrameSizeRejectsANonPositiveCap(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        $this->expectException(InvalidArgumentException::class);
        $topology->withMaxFrameSize(0);
    }

    #[Test]
    public function withAuthSecretReturnsNewInstance(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        $modified = $topology->withAuthSecret('cluster-secret');

        self::assertNotSame($topology, $modified);
        self::assertSame('cluster-secret', $modified->authSecret);
        self::assertNull($topology->authSecret);
        self::assertNull($modified->withAuthSecret(null)->authSecret, 'passing null disables auth');
    }

    #[Test]
    public function emptyAuthSecretThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authSecret');

        ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        )->withAuthSecret('');
    }

    #[Test]
    public function factoryDefaultReconnectBackoffs(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        self::assertEquals(Duration::millis(100), $topology->reconnectInitialBackoff);
        self::assertEquals(Duration::seconds(30), $topology->reconnectMaxBackoff);
    }

    #[Test]
    public function withHeartbeatIntervalReturnsNewInstance(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );
        $newInterval = Duration::millis(500);

        $modified = $topology->withHeartbeatInterval($newInterval);

        self::assertNotSame($topology, $modified);
        self::assertEquals($newInterval, $modified->heartbeatInterval);
        self::assertEquals(Duration::seconds(1), $topology->heartbeatInterval);
    }

    #[Test]
    public function withGossipIntervalReturnsNewInstance(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );
        $newInterval = Duration::millis(200);

        $modified = $topology->withGossipInterval($newInterval);

        self::assertNotSame($topology, $modified);
        self::assertEquals($newInterval, $modified->gossipInterval);
        self::assertEquals(Duration::seconds(1), $topology->gossipInterval);
    }

    #[Test]
    public function withPhiThresholdReturnsNewInstance(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        $modified = $topology->withPhiThreshold(12.5);

        self::assertNotSame($topology, $modified);
        self::assertSame(12.5, $modified->phiThreshold);
        self::assertSame(8.0, $topology->phiThreshold);
    }

    #[Test]
    public function withReconnectBackoffReturnsNewInstance(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        $modified = $topology->withReconnectBackoff(
            initialBackoff: Duration::millis(50),
            maxBackoff: Duration::seconds(60),
        );

        self::assertNotSame($topology, $modified);
        self::assertEquals(Duration::millis(50), $modified->reconnectInitialBackoff);
        self::assertEquals(Duration::seconds(60), $modified->reconnectMaxBackoff);
    }

    #[Test]
    public function withTlsReturnsNewInstance(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );
        $tls = new TlsConfig(certFile: '/certs/node.crt', keyFile: '/certs/node.key');

        $modified = $topology->withTls($tls);

        self::assertNotSame($topology, $modified);
        self::assertSame($tls, $modified->tls);
        self::assertNull($topology->tls);
    }

    #[Test]
    public function emptyClusterNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('clusterName');

        ClusterTopology::create(
            clusterName: '',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );
    }

    #[Test]
    public function emptySeedsThrowsWhenNotSingleNode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('seeds');

        ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: [],
        );
    }

    #[Test]
    public function emptySeedsAllowedForSingleNode(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: [],
            singleNode: true,
        );

        self::assertSame([], $topology->seeds);
        self::assertTrue($topology->singleNode);
    }

    #[Test]
    public function withFailureDetectionChangesAllFourKnobs(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        $modified = $topology->withFailureDetection(
            sampleSize: 100,
            minStdDev: Duration::millis(300),
            maxNoHeartbeat: Duration::seconds(20),
            phiThreshold: 10.0,
        );

        self::assertNotSame($topology, $modified);
        self::assertSame(100, $modified->phiSampleSize);
        self::assertEquals(Duration::millis(300), $modified->phiMinStdDev);
        self::assertEquals(Duration::seconds(20), $modified->maxNoHeartbeat);
        self::assertSame(10.0, $modified->phiThreshold);
        // originals unchanged
        self::assertSame(200, $topology->phiSampleSize);
        self::assertSame(8.0, $topology->phiThreshold);
    }

    #[Test]
    public function withFailureDetectionWithNullsPreservesDefaults(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        $modified = $topology->withFailureDetection();

        self::assertSame(200, $modified->phiSampleSize);
        self::assertEquals(Duration::millis(500), $modified->phiMinStdDev);
        self::assertEquals(Duration::seconds(10), $modified->maxNoHeartbeat);
        self::assertSame(8.0, $modified->phiThreshold);
    }

    #[Test]
    public function withInboundLimitsReturnsNewInstance(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        $modified = $topology->withInboundLimits(handshakeTimeout: Duration::seconds(3), maxInboundLinks: 16);

        self::assertNotSame($topology, $modified);
        self::assertEquals(Duration::seconds(3), $modified->handshakeTimeout);
        self::assertSame(16, $modified->maxInboundLinks);
        self::assertEquals(Duration::seconds(10), $topology->handshakeTimeout);
        self::assertSame(1_024, $topology->maxInboundLinks);
    }

    #[Test]
    public function withInboundLimitsWithNullsPreservesValues(): void
    {
        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
        );

        $modified = $topology->withInboundLimits();

        self::assertEquals(Duration::seconds(10), $modified->handshakeTimeout);
        self::assertSame(1_024, $modified->maxInboundLinks);
    }

    #[Test]
    public function nonPositiveMaxInboundLinksThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxInboundLinks');

        ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
            maxInboundLinks: 0,
        );
    }

    #[Test]
    public function nonPositiveHandshakeTimeoutThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('handshakeTimeout');

        ClusterTopology::create(
            clusterName: 'production',
            self: $this->self,
            bindEndpoint: $this->bindEndpoint,
            advertiseEndpoint: $this->advertiseEndpoint,
            seeds: $this->seeds,
            handshakeTimeout: Duration::zero(),
        );
    }

    protected function setUp(): void
    {
        $this->self = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $this->bindEndpoint = new NodeEndpoint(Host::of('0.0.0.0'), Port::of(7355));
        $this->advertiseEndpoint = new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355));
        $this->seeds = [new NodeEndpoint(Host::of('10.0.0.2'), Port::of(7355))];
    }
}
