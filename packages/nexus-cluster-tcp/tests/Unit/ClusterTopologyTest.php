<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\TlsConfig;
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
        self::assertSame(8.0, $topology->phiThreshold);
        self::assertEquals(Duration::seconds(1), $topology->gossipInterval);
        self::assertFalse($topology->singleNode);
        self::assertNull($topology->tls);
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

    protected function setUp(): void
    {
        $this->self = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $this->bindEndpoint = new NodeEndpoint('0.0.0.0', 7355);
        $this->advertiseEndpoint = new NodeEndpoint('10.0.0.1', 7355);
        $this->seeds = [new NodeEndpoint('10.0.0.2', 7355)];
    }
}
