<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Transport;

use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Cluster\Tcp\Protocol\FrameType;
use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackHub;
use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerConnection;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerConnectionPool;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pump-extraction target: the outbound-connection dedup, eviction, and
 * broadcast bookkeeping moved verbatim out of `ClusterNode`'s `$outboundConns` map.
 */
#[CoversClass(PeerConnectionPool::class)]
final class PeerConnectionPoolTest extends TestCase
{
    private TestRuntime $runtime;

    private LoopbackHub $hub;

    private LoopbackMeshTransport $transport;

    #[Test]
    public function dialDedupsByEndpointString(): void
    {
        $pool = $this->makePool();
        $endpoint = $this->serveEndpoint(9401);

        $first = $pool->dial($endpoint);
        $second = $pool->dial($endpoint);

        self::assertSame($first, $second, 'dial() must return the same connection for the same endpoint');
        self::assertSame(1, $pool->count());
    }

    #[Test]
    public function existingIsASideEffectFreeLookup(): void
    {
        $pool = $this->makePool();
        $endpoint = $this->serveEndpoint(9402);

        self::assertNull($pool->existing($endpoint), 'existing() must not dial as a side effect');
        self::assertSame(0, $pool->count());

        $conn = $pool->dial($endpoint);

        self::assertSame($conn, $pool->existing($endpoint));
    }

    #[Test]
    public function evictClosesAndRemovesTheConnection(): void
    {
        $pool = $this->makePool();
        $endpoint = $this->serveEndpoint(9403);

        $pool->dial($endpoint);
        self::assertSame(1, $pool->count());

        $pool->evict($endpoint);

        self::assertSame(0, $pool->count());
        self::assertNull($pool->existing($endpoint));
    }

    #[Test]
    public function evictOfAnUndialedEndpointIsANoOp(): void
    {
        $pool = $this->makePool();
        $endpoint = $this->serveEndpoint(9404);

        $pool->evict($endpoint);

        self::assertSame(0, $pool->count());
    }

    #[Test]
    public function closeAllClosesAndClearsEveryConnection(): void
    {
        $pool = $this->makePool();
        $endpointA = $this->serveEndpoint(9405);
        $endpointB = $this->serveEndpoint(9406);

        $pool->dial($endpointA);
        $pool->dial($endpointB);
        self::assertSame(2, $pool->count());

        $pool->closeAll();

        self::assertSame(0, $pool->count());
        self::assertNull($pool->existing($endpointA));
        self::assertNull($pool->existing($endpointB));
    }

    #[Test]
    public function eachVisitsEveryLiveConnectionSynchronously(): void
    {
        $pool = $this->makePool();
        $endpointA = $this->serveEndpoint(9407);
        $endpointB = $this->serveEndpoint(9408);

        $connA = $pool->dial($endpointA);
        $connB = $pool->dial($endpointB);

        /** @var list<PeerConnection> $visited */
        $visited = [];
        $pool->each(static function (PeerConnection $conn) use (&$visited): void {
            $visited[] = $conn;
        });

        self::assertCount(2, $visited);
        self::assertContains($connA, $visited);
        self::assertContains($connB, $visited);
    }

    #[Test]
    public function dialAfterEvictConstructsAFreshConnection(): void
    {
        $pool = $this->makePool();
        $endpoint = $this->serveEndpoint(9409);

        $first = $pool->dial($endpoint);
        $pool->evict($endpoint);
        $second = $pool->dial($endpoint);

        self::assertNotSame($first, $second, 'dial() after evict() must construct a fresh connection');
        self::assertSame(1, $pool->count());
    }

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->hub = new LoopbackHub();
        $this->transport = new LoopbackMeshTransport($this->hub, $this->runtime);
    }

    private function makePool(): PeerConnectionPool
    {
        return new PeerConnectionPool(
            $this->transport,
            $this->runtime,
            Duration::millis(10),
            Duration::millis(100),
            static fn(): Frame => new Frame(FrameType::Handshake, ''),
        );
    }

    private function serveEndpoint(int $port): NodeEndpoint
    {
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($port));
        $this->transport->serve($endpoint, static function (PeerLink $link): void {});

        return $endpoint;
    }
}
