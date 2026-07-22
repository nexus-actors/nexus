<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\ClusterTcp;

use Monadial\Nexus\Cluster\Tcp\DeliveryOutcome;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackHub;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackMeshTransport;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\PeerConnection;
use Monadial\Nexus\Cluster\Tcp\PeerLink;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PeerConnection::class)]
final class PeerConnectionTest extends TestCase
{
    /**
     * TDD SCENARIO 1: connect() + sendFrame() delivers a frame to the server via LoopbackMeshTransport.
     */
    #[Test]
    public function connectAndSendFrameDeliversToServer(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $serverTransport = new LoopbackMeshTransport($hub, $runtime);
        $clientTransport = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8001));

        /** @var list<Frame> $serverReceived */
        $serverReceived = [];

        $serverTransport->serve(
            $endpoint,
            static function (PeerLink $link) use (&$serverReceived): void {
                $link->onFrame(static function (Frame $frame) use (&$serverReceived): void {
                    $serverReceived[] = $frame;
                });
            },
        );

        $conn = new PeerConnection(
            $endpoint,
            $clientTransport,
            $runtime,
            Duration::millis(10),
            Duration::millis(100),
        );

        $conn->sendFrame(new Frame(FrameType::Ping, 'hello-server'));

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertCount(1, $serverReceived);
        self::assertSame(FrameType::Ping, $serverReceived[0]->type);
        self::assertSame('hello-server', $serverReceived[0]->payload);
    }

    /**
     * TDD SCENARIO 2: Frames received from the server fire the registered onFrame callback.
     */
    #[Test]
    public function receivedFramesFireOnFrameCallback(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $serverTransport = new LoopbackMeshTransport($hub, $runtime);
        $clientTransport = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8002));

        /** @var list<Frame> $clientReceived */
        $clientReceived = [];

        $serverTransport->serve(
            $endpoint,
            static function (PeerLink $link): void {
                // Server sends a frame back immediately on accept
                $link->sendFrame(new Frame(FrameType::Pong, 'from-server'));
            },
        );

        $conn = new PeerConnection(
            $endpoint,
            $clientTransport,
            $runtime,
            Duration::millis(10),
            Duration::millis(100),
        );
        $conn->onFrame(static function (Frame $frame) use (&$clientReceived): void {
            $clientReceived[] = $frame;
        });

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertCount(1, $clientReceived);
        self::assertSame(FrameType::Pong, $clientReceived[0]->type);
        self::assertSame('from-server', $clientReceived[0]->payload);
    }

    /**
     * TDD SCENARIO 3: When the peer closes the link (peer death), PeerConnection reconnects
     * and resumes delivering frames to the server.
     */
    #[Test]
    public function peerDeathTriggersReconnectAndResumedDelivery(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $serverTransport = new LoopbackMeshTransport($hub, $runtime);
        $clientTransport = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8003));

        /** @var list<PeerLink> $serverLinks */
        $serverLinks = [];
        /** @var list<Frame> $serverReceived */
        $serverReceived = [];

        $serverTransport->serve(
            $endpoint,
            static function (PeerLink $link) use (&$serverLinks, &$serverReceived): void {
                $serverLinks[] = $link;
                $link->onFrame(static function (Frame $frame) use (&$serverReceived): void {
                    $serverReceived[] = $frame;
                });
            },
        );

        $conn = new PeerConnection(
            $endpoint,
            $clientTransport,
            $runtime,
            Duration::millis(10),  // fast backoff for tests
            Duration::millis(100),
        );

        // At T=20ms: server closes its first link (simulating peer death).
        $runtime->scheduleOnce(
            Duration::millis(20),
            static function () use (&$serverLinks): void {
                $serverLinks[0]?->close();
            },
        );

        // At T=70ms: reconnect should have happened (20ms + 10ms backoff + async ticks).
        // Send a frame; it must arrive on the second server link.
        $runtime->scheduleOnce(
            Duration::millis(70),
            static function () use ($conn): void {
                $conn->sendFrame(new Frame(FrameType::Ping, 'after-reconnect'));
            },
        );

        $runtime->scheduleOnce(Duration::millis(150), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertCount(2, $serverLinks, 'Server should accept a second connection after peer death.');
        self::assertCount(1, $serverReceived, 'Frame sent after reconnect must be delivered.');
        self::assertSame('after-reconnect', $serverReceived[0]->payload);
    }

    /**
     * TDD SCENARIO 4: Calling close() sets the intentional flag so the reconnect loop stops.
     * No second server accept should occur after an intentional close.
     */
    #[Test]
    public function intentionalCloseDoesNotReconnect(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $serverTransport = new LoopbackMeshTransport($hub, $runtime);
        $clientTransport = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8004));

        $acceptCount = 0;

        $serverTransport->serve(
            $endpoint,
            static function (PeerLink $link) use (&$acceptCount): void {
                ++$acceptCount;
            },
        );

        $conn = new PeerConnection(
            $endpoint,
            $clientTransport,
            $runtime,
            Duration::millis(10),
            Duration::millis(100),
        );

        // At T=20ms: intentionally close the connection.
        $runtime->scheduleOnce(
            Duration::millis(20),
            static function () use ($conn): void {
                $conn->close();
            },
        );

        // Wait long enough that a reconnect could have fired if the flag were not set.
        $runtime->scheduleOnce(Duration::millis(120), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertSame(1, $acceptCount, 'Only the initial connection should be accepted.');
    }

    /**
     * TDD SCENARIO 6: Frames queued while disconnected are flushed to the server on reconnect.
     *
     * Verifies that the outbound queue's purpose is actually realised: after peer death the
     * client connection buffers frames, and on the next successful connect() those buffered
     * frames are delivered in order via flushQueue().
     *
     * The server transport remains registered in the LoopbackHub throughout, so the second
     * connect() succeeds with the same onAccept listener (new PeerLink, same endpoint).
     */
    #[Test]
    public function bufferedFramesAreFlushedOnReconnect(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $serverTransport = new LoopbackMeshTransport($hub, $runtime);
        $clientTransport = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8006));

        /** @var list<PeerLink> $serverLinks */
        $serverLinks = [];
        /** @var list<Frame> $serverReceived */
        $serverReceived = [];

        $serverTransport->serve(
            $endpoint,
            static function (PeerLink $link) use (&$serverLinks, &$serverReceived): void {
                $serverLinks[] = $link;
                $link->onFrame(static function (Frame $frame) use (&$serverReceived): void {
                    $serverReceived[] = $frame;
                });
            },
        );

        $conn = new PeerConnection(
            $endpoint,
            $clientTransport,
            $runtime,
            Duration::millis(10),   // fast backoff so reconnect fires well before T=150ms
            Duration::millis(100),
        );

        // At T=20ms: server closes its first accepted link (simulates peer death).
        $runtime->scheduleOnce(
            Duration::millis(20),
            static function () use (&$serverLinks): void {
                $serverLinks[0]?->close();
            },
        );

        // At T=25ms: client has seen onClose (async but within same event-loop cycle after T=20ms)
        // and $this->link is now null. sendFrame() must queue the frame, not drop it.
        $runtime->scheduleOnce(
            Duration::millis(25),
            static function () use ($conn): void {
                $conn->sendFrame(new Frame(FrameType::Ping, 'queued-while-down'));
            },
        );

        // Shutdown at T=150ms; reconnect fires at approximately T=30-35ms, well before this.
        $runtime->scheduleOnce(Duration::millis(150), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertCount(2, $serverLinks, 'Server should accept a second connection after peer death.');
        self::assertSame(0, $conn->drops(), 'No frames should be dropped — queue cap not exceeded.');
        self::assertCount(1, $serverReceived, 'Buffered frame must be delivered to server after reconnect.');
        self::assertSame('queued-while-down', $serverReceived[0]->payload);
    }

    /**
     * TDD SCENARIO 5: Frames sent while reconnecting buffer up to the queue cap.
     * Frames beyond the cap are dropped and counted via drops().
     */
    #[Test]
    public function queueOverflowDuringReconnectDropsFrames(): void
    {
        $hub = new LoopbackHub();
        $clientTransport = new LoopbackMeshTransport($hub, new FiberRuntime());
        // No server registered on hub — connect() will throw immediately, leaving link = null.
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8005));

        $conn = new PeerConnection(
            $endpoint,
            $clientTransport,
            new FiberRuntime(),
            Duration::seconds(60),  // long backoff so we stay disconnected during the test
            Duration::seconds(120),
            2,                      // queueCap = 2 to trigger overflow easily
        );

        // 4 frames sent while disconnected: 2 buffered, 2 dropped — each send reports its own outcome.
        self::assertSame(DeliveryOutcome::Buffered, $conn->sendFrame(new Frame(FrameType::Ping, '1')));
        self::assertSame(DeliveryOutcome::Buffered, $conn->sendFrame(new Frame(FrameType::Ping, '2')));
        self::assertSame(DeliveryOutcome::Dropped, $conn->sendFrame(new Frame(FrameType::Ping, '3')));
        self::assertSame(DeliveryOutcome::Dropped, $conn->sendFrame(new Frame(FrameType::Ping, '4')));

        self::assertSame(2, $conn->drops());
    }

    /**
     * REL-009: a send over a live link is admitted (its bytes leave the process).
     */
    #[Test]
    public function sendFrameReturnsAdmittedOverLiveLink(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $serverTransport = new LoopbackMeshTransport($hub, $runtime);
        $clientTransport = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8007));

        $serverTransport->serve(
            $endpoint,
            static function (PeerLink $link): void {
                $link->onFrame(static function (Frame $frame): void {});
            },
        );

        $conn = new PeerConnection(
            $endpoint,
            $clientTransport,
            $runtime,
            Duration::millis(10),
            Duration::millis(100),
        );

        // The loopback transport connects synchronously in the constructor, so the link is live now.
        self::assertSame(DeliveryOutcome::Admitted, $conn->sendFrame(new Frame(FrameType::Ping, 'live')));
    }

    /**
     * REL-009: a send after an intentional close is dropped, never silently swallowed.
     */
    #[Test]
    public function sendFrameReturnsDroppedAfterClose(): void
    {
        $hub = new LoopbackHub();
        $clientTransport = new LoopbackMeshTransport($hub, new FiberRuntime());
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8008));

        $conn = new PeerConnection(
            $endpoint,
            $clientTransport,
            new FiberRuntime(),
            Duration::seconds(60),
            Duration::seconds(120),
        );

        $conn->close();

        self::assertSame(DeliveryOutcome::Dropped, $conn->sendFrame(new Frame(FrameType::Ping, 'after-close')));
    }
}
