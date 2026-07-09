<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterNode;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Swoole\SwooleMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Pong;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

use function array_sum;
use function count;
use function fwrite;
use function hrtime;
use function number_format;
use function sort;
use function sprintf;
use function str_repeat;
use function strlen;

use const STDERR;

/**
 * Per-core efficiency benchmark for the nexus-cluster-tcp mesh stack.
 *
 * Two ClusterNodes are booted in ONE Swoole process over the real
 * {@see SwooleMeshTransport} on 127.0.0.1 ephemeral ports, so every message
 * traverses the full remote path — MessagePack serialize, length-prefix framing,
 * loopback TCP, deserialize, mailbox enqueue, handler — but with no Docker bridge
 * or physical NIC in the way. One Swoole reactor drives both nodes, so these
 * numbers characterise the software cost on a SINGLE core; whole-machine
 * saturation is measured separately by the multi-container harness.
 *
 * Termination discipline mirrors ClusterNodeSwooleTest: fresh runtime/system per
 * test, ephemeral ports, all work inside one scheduleOnce coroutine, bounded
 * polls (never while(true)), finally teardown, measurements captured to
 * outer-scope vars and reported after $system->run() returns.
 *
 * Run: docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance --filter=ClusterTcp
 *
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress InvalidOperand int/float mixing in the throughput/latency math is intentional.
 * @psalm-suppress InvalidArgument, MixedOperand, MixedArgument, InvalidArrayOffset, UnusedVariable, UnevaluatedCode benchmark scaffolding, not scanned by the project Psalm config.
 */
#[RequiresPhpExtension('swoole')]
final class ClusterTcpPerformanceTest extends TestCase
{
    /**
     * Cross-node tell throughput across a payload-size sweep. For each size we
     * fire N one-way tells from node A to a sink actor exposed on node B and
     * measure end-to-end delivery rate (messages/sec) and wire bandwidth (MB/s).
     */
    #[Test]
    public function remoteTellThroughput(): void
    {
        /** @var list<array{bytes: int, count: int, elapsedMs: float}> $results */
        $results = [];

        $sweep = [
            ['bytes' => 64, 'count' => 100_000],
            ['bytes' => 1_024, 'count' => 100_000],
            ['bytes' => 16_384, 'count' => 50_000],
        ];

        foreach ($sweep as $case) {
            $results[] = $this->measureTellThroughput($case['bytes'], $case['count']);
        }

        fwrite(STDERR, "\n  [cluster-tcp] remote tell throughput (single reactor, loopback TCP)\n");

        foreach ($results as $r) {
            $msgPerSec = $r['elapsedMs'] > 0.0
                ? $r['count'] / $r['elapsedMs'] * 1000.0
                : 0.0;
            $mbPerSec = $r['elapsedMs'] > 0.0
                ? $r['count'] * $r['bytes'] / $r['elapsedMs'] * 1000.0 / 1_048_576.0
                : 0.0;

            fwrite(STDERR, sprintf(
                "    payload %6s B | %9s msgs | %7.1f ms | %10s msg/s | %7.1f MB/s\n",
                number_format($r['bytes']),
                number_format($r['count']),
                $r['elapsedMs'],
                number_format($msgPerSec),
                $mbPerSec,
            ));

            self::assertGreaterThan(0, $r['count'], 'messages must have been delivered');
            self::assertGreaterThan(0.0, $msgPerSec);
        }
    }

    /**
     * Cross-node ask round-trip latency. Sequential (one in-flight) asks from
     * node A to a responder on node B; each round-trip is timed and reported as a
     * percentile distribution — the cleanest measure of per-message wire cost.
     */
    #[Test]
    public function remoteAskLatency(): void
    {
        $rounds = 20_000;
        /** @var list<int> $samples nanoseconds */
        $samples = [];

        $this->withPair(
            static function (ClusterNode $a, ClusterNode $b, NodeAddress $addrB, ActorSystem $system) use ($rounds, &$samples): void {
                // Responder on B: echoes each Ping back as a Pong to the sender.
                $responder = $system->spawn(
                    Props::fromBehavior(Behavior::receive(
                        static function (ActorContext $ctx, object $msg): Behavior {
                            if ($msg instanceof Ping) {
                                $ctx->sender()?->tell(new Pong($msg->text));
                            }

                            return Behavior::same();
                        },
                    )),
                    'ask-responder',
                );
                $b->expose($responder);
                $path = $responder->path();

                $ref = $a->refFor($addrB, $path);

                // Warm-up: prime connections, JIT, and the phi window before timing.
                for ($i = 0; $i < 1_000; ++$i) {
                    $ref->ask(new Ping('warmup'), Duration::seconds(2))->await();
                }

                for ($i = 0; $i < $rounds; ++$i) {
                    $t0 = hrtime(true);
                    $ref->ask(new Ping('x'), Duration::seconds(2))->await();
                    $samples[] = hrtime(true) - $t0;
                }
            },
        );

        self::assertGreaterThan(0, count($samples), 'ask round-trips must have completed');

        sort($samples);
        $p = static fn(float $q): int => $samples[(int) ($q * (count($samples) - 1))];
        $mean = array_sum($samples) / count($samples);

        fwrite(STDERR, sprintf(
            "\n  [cluster-tcp] remote ask round-trip latency (%s samples, single in-flight)\n"
            . "    mean %.1f us | p50 %.1f us | p90 %.1f us | p99 %.1f us | p99.9 %.1f us | max %.1f us\n"
            . "    throughput (sequential ask/reply): %s asks/s\n",
            number_format(count($samples)),
            $mean / 1_000.0,
            $p(0.50) / 1_000.0,
            $p(0.90) / 1_000.0,
            $p(0.99) / 1_000.0,
            $p(0.999) / 1_000.0,
            $samples[count($samples) - 1] / 1_000.0,
            number_format(1_000_000_000.0 / $mean),
        ));

        self::assertGreaterThan(0, $p(0.50));
    }

    /**
     * Local short-circuit baseline: a ClusterRef targeting an actor on THIS node
     * delivers in-process without touching the wire. Contrast with
     * {@see remoteTellThroughput} to see the pure transport overhead.
     */
    #[Test]
    public function localShortCircuitTellThroughput(): void
    {
        $count = 200_000;
        $elapsedMs = 0.0;
        $delivered = 0;

        $this->withPair(
            static function (ClusterNode $a, ClusterNode $b, NodeAddress $addrB, ActorSystem $system) use ($count, &$elapsedMs, &$delivered): void {
                // Sink exposed on A; addressed via A's OWN address → local short-circuit.
                $sink = $system->spawn(
                    Props::fromBehavior(Behavior::receive(
                        static function (ActorContext $ctx, object $msg) use (&$delivered): Behavior {
                            if ($msg instanceof Ping) {
                                ++$delivered;
                            }

                            return Behavior::same();
                        },
                    )),
                    'local-sink',
                );
                $a->expose($sink);

                $ref = $a->refFor($a->self(), $sink->path());
                $payload = str_repeat('x', 64);

                $start = hrtime(true);

                for ($i = 0; $i < $count; ++$i) {
                    $ref->tell(new Ping($payload));
                }

                for ($waited = 0.0; $delivered < $count && $waited < 10.0; $waited += 0.005) {
                    Coroutine::sleep(0.005);
                }

                $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
            },
        );

        $msgPerSec = $elapsedMs > 0.0
            ? $delivered / $elapsedMs * 1000.0
            : 0.0;

        fwrite(STDERR, sprintf(
            "\n  [cluster-tcp] local short-circuit tell throughput (no wire)\n"
            . "    %s msgs | %.1f ms | %s msg/s (%d delivered)\n",
            number_format($count),
            $elapsedMs,
            number_format($msgPerSec),
            $delivered,
        ));

        self::assertGreaterThan(0.0, $msgPerSec);
    }

    /**
     * @return array{bytes: int, count: int, elapsedMs: float}
     */
    private function measureTellThroughput(int $payloadBytes, int $count): array
    {
        $elapsedMs = 0.0;
        $delivered = 0;

        $this->withPair(
            static function (ClusterNode $a, ClusterNode $b, NodeAddress $addrB, ActorSystem $system) use ($payloadBytes, $count, &$elapsedMs, &$delivered): void {
                $sink = $system->spawn(
                    Props::fromBehavior(Behavior::receive(
                        static function (ActorContext $ctx, object $msg) use (&$delivered): Behavior {
                            if ($msg instanceof Ping) {
                                ++$delivered;
                            }

                            return Behavior::same();
                        },
                    )),
                    'throughput-sink',
                );
                $b->expose($sink);

                $ref = $a->refFor($addrB, $sink->path());
                $payload = str_repeat('x', $payloadBytes);

                // Warm-up: establish the link and let the socket buffers settle.
                for ($i = 0; $i < 2_000; ++$i) {
                    $ref->tell(new Ping($payload));
                }

                for ($waited = 0.0; $delivered < 2_000 && $waited < 5.0; $waited += 0.005) {
                    Coroutine::sleep(0.005);
                }

                $delivered = 0;
                $start = hrtime(true);

                for ($i = 0; $i < $count; ++$i) {
                    $ref->tell(new Ping($payload));
                }

                for ($waited = 0.0; $delivered < $count && $waited < 30.0; $waited += 0.005) {
                    Coroutine::sleep(0.005);
                }

                $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
            },
        );

        // Report the payload string size as the wire-payload proxy (MessagePack adds a few bytes).
        return ['bytes' => strlen(str_repeat('x', $payloadBytes)), 'count' => $delivered, 'elapsedMs' => $elapsedMs];
    }

    /**
     * Boot a two-node cluster over real loopback TCP, run $body(nodeA, nodeB,
     * addrB, system) once both nodes see each other Up, then tear everything down.
     * All of it runs inside a single Co\run so the caller's assertions execute
     * after run() returns.
     *
     * @param callable(ClusterNode, ClusterNode, NodeAddress, ActorSystem): void $body
     */
    private function withPair(callable $body): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-tcp-bench', $runtime);

        $addrA = new NodeAddress('bench', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('bench', 'local', 'nexus', 'node-b');

        $runtime->scheduleOnce(Duration::millis(1), function () use ($runtime, $system, $addrA, $addrB, $body): void {
            $transportA = null;
            $transportB = null;
            $nodeA = null;
            $nodeB = null;

            try {
                $transportA = new SwooleMeshTransport($runtime);
                $portA = $transportA->bindEphemeral(Host::of('127.0.0.1'));
                $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($portA));

                $transportB = new SwooleMeshTransport($runtime);
                $portB = $transportB->bindEphemeral(Host::of('127.0.0.1'));
                $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($portB));

                $nodeB = ClusterNode::boot(
                    $system,
                    $this->topology($addrB, $endpointB, [$endpointA]),
                    $this->userTypes(),
                    $transportB,
                );
                $nodeA = ClusterNode::boot(
                    $system,
                    $this->topology($addrA, $endpointA, [$endpointB]),
                    $this->userTypes(),
                    $transportA,
                );

                for ($i = 0; $i < 200; ++$i) {
                    if (count($nodeA->view()->upNodes()) === 2 && count($nodeB->view()->upNodes()) === 2) {
                        break;
                    }

                    Coroutine::sleep(0.02);
                }

                $body($nodeA, $nodeB, $addrB, $system);
            } finally {
                $nodeA?->shutdown();
                $nodeB?->shutdown();
                $transportA?->close();
                $transportB?->close();
                $system->shutdown(Duration::seconds(1));
            }
        });

        $system->run();
    }

    /**
     * @param list<NodeEndpoint> $seeds
     */
    private function topology(NodeAddress $self, NodeEndpoint $endpoint, array $seeds): ClusterTopology
    {
        return ClusterTopology::create(
            clusterName: 'bench-cluster',
            self: $self,
            bindEndpoint: $endpoint,
            advertiseEndpoint: $endpoint,
            seeds: $seeds,
        )
            ->withHeartbeatInterval(Duration::millis(200))
            ->withGossipInterval(Duration::millis(200))
            ->withReconnectBackoff(Duration::millis(50), Duration::millis(500));
    }

    private function userTypes(): TypeRegistry
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Ping::class);
        $registry->registerFromAttribute(Pong::class);

        return $registry;
    }
}
