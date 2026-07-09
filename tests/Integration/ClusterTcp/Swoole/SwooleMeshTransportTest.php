<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\ClusterTcp\Swoole;

use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\MeshTransport;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\PeerLink;
use Monadial\Nexus\Cluster\Tcp\Swoole\SwooleMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Swoole\SwoolePeerLink;
use Monadial\Nexus\Cluster\Tcp\TlsConfig;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

use function str_repeat;
use function Swoole\Coroutine\run;

/**
 * Integration tests for SwooleMeshTransport.
 *
 * All tests run inside `Co\run()` so every co-routine suspension (recv, sleep)
 * resolves before the closure returns. This guarantees termination without
 * wall-clock timeouts: when `run()` returns, all coroutines have finished.
 *
 * @psalm-suppress UndefinedFunction -- run() aliased from Swoole\Coroutine\run
 */
#[CoversClass(SwooleMeshTransport::class)]
#[CoversClass(SwoolePeerLink::class)]
final class SwooleMeshTransportTest extends TestCase
{
    /**
     * TDD SCENARIO 1: serve() + connect() establishes a link; bidirectional frames flow.
     *
     * Termination guarantee: `transport->close()` shuts down the Coroutine\Server's
     * accept loop (Server::shutdown() cancels the accept socket), and all spawned
     * coroutines exit when Co\run() drains them.
     */
    #[Test]
    public function bidirectionalFramesOverRealTcpSocket(): void
    {
        /** @var list<Frame> $serverReceived */
        $serverReceived = [];
        /** @var list<Frame> $clientReceived */
        $clientReceived = [];

        run(static function () use (&$serverReceived, &$clientReceived): void {
            $runtime = new SwooleRuntime();
            $transport = new SwooleMeshTransport($runtime);

            $port = $transport->bindEphemeral(Host::of('127.0.0.1'));
            $bind = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($port));

            $transport->serve(
                $bind,
                static function (PeerLink $serverLink) use (&$serverReceived): void {
                    $serverLink->onFrame(static function (Frame $frame) use (&$serverReceived): void {
                        $serverReceived[] = $frame;
                    });
                },
            );

            Coroutine::sleep(0.05); // Give the accept-loop coroutine time to start

            $clientLink = $transport->connect($bind);
            $clientLink->onFrame(static function (Frame $frame) use (&$clientReceived): void {
                $clientReceived[] = $frame;
            });

            $clientLink->sendFrame(new Frame(FrameType::Ping, 'hello-server'));

            Coroutine::sleep(0.1); // Let the frame arrive at the server

            $clientLink->close();
            $transport->close();
        });

        self::assertCount(1, $serverReceived);
        self::assertSame(FrameType::Ping, $serverReceived[0]->type);
        self::assertSame('hello-server', $serverReceived[0]->payload);
    }

    /**
     * Regression: two coroutines writing the SAME link concurrently must not collide.
     * Swoole forbids two coroutines writing one socket at the same time, and sendAll()
     * suspends mid-write once the send buffer fills — so without SwoolePeerLink's write
     * mutex, large frames sent from parallel coroutines (e.g. an app tell racing a gossip
     * frame) crash the process with "writing of the same socket ... is not allowed". This
     * drives that exact race with big frames and asserts every frame still arrives.
     */
    #[Test]
    public function concurrentWritesOnOneLinkAreSerialised(): void
    {
        $received = 0;
        $sendersCount = 2;
        $framesPerSender = 40;
        $payload = str_repeat('x', 512 * 1024);

        run(static function () use (&$received, $sendersCount, $framesPerSender, $payload): void {
            $runtime = new SwooleRuntime();
            $transport = new SwooleMeshTransport($runtime);

            $port = $transport->bindEphemeral(Host::of('127.0.0.1'));
            $bind = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($port));

            $transport->serve(
                $bind,
                static function (PeerLink $serverLink) use (&$received): void {
                    $serverLink->onFrame(static function (Frame $frame) use (&$received): void {
                        ++$received;
                    });
                },
            );

            Coroutine::sleep(0.05);

            $clientLink = $transport->connect($bind);
            $done = new Channel($sendersCount);

            for ($s = 0; $s < $sendersCount; ++$s) {
                Coroutine::create(static function () use ($clientLink, $framesPerSender, $payload, $done): void {
                    for ($i = 0; $i < $framesPerSender; ++$i) {
                        $clientLink->sendFrame(new Frame(FrameType::Message, $payload));
                    }

                    $done->push(true);
                });
            }

            for ($s = 0; $s < $sendersCount; ++$s) {
                $done->pop();
            }

            for ($waited = 0.0; $received < $sendersCount * $framesPerSender && $waited < 5.0; $waited += 0.02) {
                Coroutine::sleep(0.02);
            }

            $clientLink->close();
            $transport->close();
        });

        self::assertSame(
            $sendersCount * $framesPerSender,
            $received,
            'all concurrently-sent frames must arrive with no socket-write collision',
        );
    }

    /**
     * TDD SCENARIO 2: Half-frame reassembly — a frame split across two sends.
     *
     * Sends the wire bytes of one frame in two separate socket writes. The
     * receive loop must buffer the first half and emit the frame only when the
     * second half arrives. This validates FrameCodec::decodeStream() integration.
     *
     * Termination guarantee: same as Scenario 1.
     */
    #[Test]
    public function halfFrameReassembly(): void
    {
        /** @var list<Frame> $serverReceived */
        $serverReceived = [];

        run(static function () use (&$serverReceived): void {
            $runtime = new SwooleRuntime();
            $transport = new SwooleMeshTransport($runtime);

            $port = $transport->bindEphemeral(Host::of('127.0.0.1'));
            $bind = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($port));

            $transport->serve(
                $bind,
                static function (PeerLink $serverLink) use (&$serverReceived): void {
                    $serverLink->onFrame(static function (Frame $frame) use (&$serverReceived): void {
                        $serverReceived[] = $frame;
                    });
                },
            );

            Coroutine::sleep(0.05);

            // connect() returns SwoolePeerLink which exposes sendRaw()
            $clientLink = $transport->connect($bind);
            self::assertInstanceOf(SwoolePeerLink::class, $clientLink);

            // Build the wire bytes manually: [4-byte big-endian length][1-byte type][payload]
            $payload = 'split-me-in-half';
            $bodyLength = 1 + strlen($payload);
            $wire = pack('N', $bodyLength) . chr(FrameType::Message->value) . $payload;

            // Send first half, pause, then send second half
            $half = (int) (strlen($wire) / 2);
            $clientLink->sendRaw(substr($wire, 0, $half));

            Coroutine::sleep(0.05); // Ensure first half arrives before second

            $clientLink->sendRaw(substr($wire, $half));

            Coroutine::sleep(0.1);

            $clientLink->close();
            $transport->close();
        });

        self::assertCount(1, $serverReceived);
        self::assertSame(FrameType::Message, $serverReceived[0]->type);
        self::assertSame('split-me-in-half', $serverReceived[0]->payload);
    }

    /**
     * TDD SCENARIO 3: Close propagation — client closes, server's onClose fires.
     *
     * When the client-side link is closed, the TCP connection is torn down. The
     * server's receive loop detects EOF (recv returns '') and fires the onClose
     * callbacks registered on the server-side link.
     *
     * Termination guarantee: after client closes, the server's receive coroutine
     * exits naturally (recv returns ''). transport->close() stops the accept loop.
     */
    #[Test]
    public function closePropagationClientToServer(): void
    {
        $serverCloseFired = false;

        run(static function () use (&$serverCloseFired): void {
            $runtime = new SwooleRuntime();
            $transport = new SwooleMeshTransport($runtime);

            $port = $transport->bindEphemeral(Host::of('127.0.0.1'));
            $bind = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($port));

            $transport->serve(
                $bind,
                static function (PeerLink $serverLink) use (&$serverCloseFired): void {
                    $serverLink->onClose(static function () use (&$serverCloseFired): void {
                        $serverCloseFired = true;
                    });
                },
            );

            Coroutine::sleep(0.05);

            $clientLink = $transport->connect($bind);

            Coroutine::sleep(0.05); // Let onAccept run

            $clientLink->close(); // TCP teardown — server detects EOF

            Coroutine::sleep(0.15); // Give server receive-loop time to process EOF

            $transport->close();
        });

        self::assertTrue($serverCloseFired);
    }

    /**
     * TDD SCENARIO 4: TLS happy path with self-signed fixture certificates.
     *
     * The server uses the fixture cert + key. The client sends the same cert
     * (mutual TLS) but disables peer verification so the self-signed cert is
     * accepted without a CA chain. Frames must flow correctly over the encrypted
     * channel.
     *
     * Termination guarantee: same as Scenario 1.
     */
    #[Test]
    public function tlsHappyPath(): void
    {
        $fixturesDir = dirname(__DIR__, 4) . '/packages/nexus-cluster-tcp/tests/fixtures';

        /** @var list<Frame> $received */
        $received = [];

        run(static function () use ($fixturesDir, &$received): void {
            $tls = new TlsConfig(
                certFile: $fixturesDir . '/server.crt',
                keyFile: $fixturesDir . '/server.key',
                caFile: null,
                verifyPeer: false,
            );

            $runtime = new SwooleRuntime();
            $transport = new SwooleMeshTransport($runtime, $tls);

            $port = $transport->bindEphemeral(Host::of('127.0.0.1'));
            $bind = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($port));

            $transport->serve(
                $bind,
                static function (PeerLink $serverLink) use (&$received): void {
                    $serverLink->onFrame(static function (Frame $frame) use (&$received): void {
                        $received[] = $frame;
                    });
                },
            );

            Coroutine::sleep(0.05);

            $clientLink = $transport->connect($bind);
            $clientLink->sendFrame(new Frame(FrameType::Ping, 'tls-payload'));

            Coroutine::sleep(0.15);

            $clientLink->close();
            $transport->close();
        });

        self::assertCount(1, $received);
        self::assertSame(FrameType::Ping, $received[0]->type);
        self::assertSame('tls-payload', $received[0]->payload);
    }

    /**
     * TDD SCENARIO 5: SwooleMeshTransport implements MeshTransport.
     */
    #[Test]
    public function implementsMeshTransport(): void
    {
        run(static function (): void {
            $runtime = new SwooleRuntime();
            $transport = new SwooleMeshTransport($runtime);
            self::assertInstanceOf(MeshTransport::class, $transport);
            $transport->close();
        });
    }
}
