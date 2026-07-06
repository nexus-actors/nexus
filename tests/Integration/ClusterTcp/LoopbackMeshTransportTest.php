<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\ClusterTcp;

use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackHub;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackPeerLink;
use Monadial\Nexus\Cluster\Tcp\MeshTransport;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\PeerLink;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LoopbackHub::class)]
#[CoversClass(LoopbackMeshTransport::class)]
#[CoversClass(LoopbackPeerLink::class)]
final class LoopbackMeshTransportTest extends TestCase
{
    /**
     * TDD SCENARIO 1: serve() + connect() causes onAccept to fire with a server-side PeerLink.
     */
    #[Test]
    public function serveAndConnectFiresOnAccept(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $nodeA = new LoopbackMeshTransport($hub, $runtime);
        $nodeB = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7001));

        /** @var PeerLink|null $acceptedLink */
        $acceptedLink = null;

        $nodeA->serve($endpoint, static function (PeerLink $link) use (&$acceptedLink): void {
            $acceptedLink = $link;
        });

        $nodeB->connect($endpoint);

        // Safety shutdown so run() always terminates
        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertInstanceOf(PeerLink::class, $acceptedLink);
    }

    /**
     * TDD SCENARIO 2: The client-side link's remote() returns the endpoint it connected to.
     */
    #[Test]
    public function remoteReturnsConnectedEndpointForClientLink(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $nodeA = new LoopbackMeshTransport($hub, $runtime);
        $nodeB = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7002));

        $nodeA->serve($endpoint, static function (PeerLink $link): void {});

        $clientLink = $nodeB->connect($endpoint);

        // remote() is synchronous — no run() needed
        self::assertNotNull($clientLink->remote());
        self::assertSame('127.0.0.1:7002', (string) $clientLink->remote());
    }

    /**
     * TDD SCENARIO 3: The server-side link's remote() returns null (loopback client has no fixed address).
     */
    #[Test]
    public function remoteReturnsNullForServerLink(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $nodeA = new LoopbackMeshTransport($hub, $runtime);
        $nodeB = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7003));

        /** @var PeerLink|null $serverLink */
        $serverLink = null;

        $nodeA->serve($endpoint, static function (PeerLink $link) use (&$serverLink): void {
            $serverLink = $link;
        });

        $nodeB->connect($endpoint);

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertNotNull($serverLink);
        self::assertNull($serverLink->remote());
    }

    /**
     * TDD SCENARIO 4: Frames flow from client to server and arrive at the server's onFrame callback.
     * Real frame bytes are asserted (type + payload), not mocks.
     */
    #[Test]
    public function framesFlowFromClientToServer(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $nodeA = new LoopbackMeshTransport($hub, $runtime);
        $nodeB = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7004));

        /** @var list<Frame> $serverReceived */
        $serverReceived = [];

        $nodeA->serve($endpoint, static function (PeerLink $serverLink) use (&$serverReceived): void {
            $serverLink->onFrame(static function (Frame $frame) use (&$serverReceived): void {
                $serverReceived[] = $frame;
            });
        });

        $clientLink = $nodeB->connect($endpoint);
        $clientLink->sendFrame(new Frame(FrameType::Ping, 'hello-server'));

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertCount(1, $serverReceived);
        self::assertSame(FrameType::Ping, $serverReceived[0]->type);
        self::assertSame('hello-server', $serverReceived[0]->payload);
    }

    /**
     * TDD SCENARIO 5: Frames flow in both directions — client→server and server→client.
     * The server echoes a Pong for every Ping it receives; the client's onFrame fires.
     */
    #[Test]
    public function framesFlowBidirectionally(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $nodeA = new LoopbackMeshTransport($hub, $runtime);
        $nodeB = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7005));

        /** @var list<Frame> $serverReceived */
        $serverReceived = [];
        /** @var list<Frame> $clientReceived */
        $clientReceived = [];

        // Server registers an echo handler: Ping → Pong
        $nodeA->serve($endpoint, static function (PeerLink $serverLink) use (&$serverReceived): void {
            $serverLink->onFrame(
                static function (Frame $frame) use (&$serverReceived, $serverLink): void {
                    $serverReceived[] = $frame;
                    $serverLink->sendFrame(new Frame(FrameType::Pong, 'pong-reply'));
                },
            );
        });

        $clientLink = $nodeB->connect($endpoint);
        $clientLink->onFrame(static function (Frame $frame) use (&$clientReceived): void {
            $clientReceived[] = $frame;
        });

        $clientLink->sendFrame(new Frame(FrameType::Ping, 'ping-request'));

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        // client → server
        self::assertCount(1, $serverReceived);
        self::assertSame(FrameType::Ping, $serverReceived[0]->type);
        self::assertSame('ping-request', $serverReceived[0]->payload);

        // server → client (echo)
        self::assertCount(1, $clientReceived);
        self::assertSame(FrameType::Pong, $clientReceived[0]->type);
        self::assertSame('pong-reply', $clientReceived[0]->payload);
    }

    /**
     * TDD SCENARIO 6: close() on the client end fires the server-side onClose callback.
     */
    #[Test]
    public function closingClientLinkFiresServerOnClose(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $nodeA = new LoopbackMeshTransport($hub, $runtime);
        $nodeB = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7006));

        $serverCloseFired = false;

        $nodeA->serve($endpoint, static function (PeerLink $serverLink) use (&$serverCloseFired): void {
            $serverLink->onClose(static function () use (&$serverCloseFired): void {
                $serverCloseFired = true;
            });
        });

        $clientLink = $nodeB->connect($endpoint);

        // close() is called after connect spawns the onAccept fiber; both run on first tick
        $clientLink->close();

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertTrue($serverCloseFired);
    }

    /**
     * TDD SCENARIO 7: close() on the server end fires the client-side onClose callback.
     */
    #[Test]
    public function closingServerLinkFiresClientOnClose(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $nodeA = new LoopbackMeshTransport($hub, $runtime);
        $nodeB = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7007));

        $clientCloseFired = false;

        $nodeA->serve($endpoint, static function (PeerLink $serverLink): void {
            // Close from the server side immediately in onAccept
            $serverLink->close();
        });

        $clientLink = $nodeB->connect($endpoint);
        $clientLink->onClose(static function () use (&$clientCloseFired): void {
            $clientCloseFired = true;
        });

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertTrue($clientCloseFired);
    }

    /**
     * TDD SCENARIO 8: connect() throws RuntimeException when no server is listening.
     * Connecting to an unserved endpoint is a programming error in this loopback context.
     */
    #[Test]
    public function connectToUnservedEndpointThrows(): void
    {
        $hub = new LoopbackHub();
        $nodeB = new LoopbackMeshTransport($hub, new FiberRuntime());
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7008));

        $this->expectException(RuntimeException::class);
        $nodeB->connect($endpoint);
    }

    /**
     * TDD SCENARIO 9: close() on the server transport unregisters its listener from the hub
     * so subsequent connect() attempts to that endpoint fail.
     */
    #[Test]
    public function connectingAfterServerClosesThrows(): void
    {
        $hub = new LoopbackHub();
        $nodeA = new LoopbackMeshTransport($hub, new FiberRuntime());
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7009));

        // Serve on nodeA
        $nodeA->serve($endpoint, static function (PeerLink $link): void {});

        // Create a client and verify connection works
        $clientA = new LoopbackMeshTransport($hub, new FiberRuntime());
        $link1 = $clientA->connect($endpoint);
        self::assertNotNull($link1);

        // Close the server
        $nodeA->close();

        // Create another client and verify connection fails
        $clientB = new LoopbackMeshTransport($hub, new FiberRuntime());
        $this->expectException(RuntimeException::class);
        $clientB->connect($endpoint);
    }

    /**
     * TDD SCENARIO 10: LoopbackMeshTransport implements MeshTransport.
     */
    #[Test]
    public function loopbackTransportImplementsMeshTransport(): void
    {
        $hub = new LoopbackHub();
        $transport = new LoopbackMeshTransport($hub, new FiberRuntime());

        self::assertInstanceOf(MeshTransport::class, $transport);
    }
}
