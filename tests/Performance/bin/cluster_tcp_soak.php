<?php

declare(strict_types=1);

/**
 * Cluster-tcp soak test: sustained load for N seconds with a health time-series.
 *
 * Boots one two-node cluster (real SwooleMeshTransport on 127.0.0.1) and drives a
 * continuous tell flood from node A to a sink on node B for the whole duration,
 * reporting every 10 s:
 *
 *   [t=010s] interval=... msg/s | total=... | ask p50=... us | mem=... MB (peak ...) | suspected=0 down=0
 *
 * This catches what short benchmarks cannot: memory leaks (mem must stay flat),
 * throughput drift (interval rate must not decay), scheduler starvation (the ask
 * probe must stay fast), and false failure detection under load (suspected/down
 * must stay zero — the membership actor must keep processing gossip while the
 * data path is saturated).
 *
 * Exit code 0 = PASS, 1 = FAIL. Criteria printed in the summary.
 *
 * Usage:
 *   docker compose exec php-swoole php tests/Performance/bin/cluster_tcp_soak.php [seconds] [payloadBytes]
 */

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterNode;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
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
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Coroutine;

require __DIR__ . '/../../../vendor/autoload.php';

$durationSeconds = isset($argv[1])
    ? (int) $argv[1]
    : 300;
$payloadBytes = isset($argv[2])
    ? (int) $argv[2]
    : 1_024;

// Membership health: count suspicion/down events across BOTH nodes (shared system).
$suspected = 0;
$down = 0;

$dispatcher = new class ($suspected, $down) implements EventDispatcherInterface {
    public function __construct(private int &$suspected, private int &$down) {}

    public function dispatch(object $event): object
    {
        if ($event instanceof NodeSuspected) {
            ++$this->suspected;
        }

        if ($event instanceof NodeDown) {
            ++$this->down;
        }

        return $event;
    }
};

$runtime = new SwooleRuntime();
$system = ActorSystem::create('cluster-tcp-soak', $runtime, eventDispatcher: $dispatcher);

$addrA = new NodeAddress('soak', 'local', 'nexus', 'node-a');
$addrB = new NodeAddress('soak', 'local', 'nexus', 'node-b');

$makeTopology = static function (NodeAddress $self, NodeEndpoint $endpoint, array $seeds): ClusterTopology {
    return ClusterTopology::create(
        clusterName: 'soak-cluster',
        self: $self,
        bindEndpoint: $endpoint,
        advertiseEndpoint: $endpoint,
        seeds: $seeds,
    );

    // NOTE: production-default heartbeat/gossip/phi settings — the soak must prove
    // failure detection stays quiet under load with the real cadence, not a tuned one.
};

$makeTypes = static function (): TypeRegistry {
    $registry = new TypeRegistry();
    $registry->registerFromAttribute(Ping::class);
    $registry->registerFromAttribute(Pong::class);

    return $registry;
};

/** @var list<array{t: int, rate: float, askP50Us: float, memMb: float}> $intervals */
$intervals = [];
$delivered = 0;
$failReasons = [];

$runtime->scheduleOnce(Duration::millis(1), static function () use (
    $runtime,
    $system,
    $addrA,
    $addrB,
    $makeTopology,
    $makeTypes,
    $durationSeconds,
    $payloadBytes,
    &$delivered,
    &$intervals,
    &$suspected,
    &$down,
): void {
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

        $nodeB = ClusterNode::boot($system, $makeTopology($addrB, $endpointB, [$endpointA]), $makeTypes(), $transportB);
        $nodeA = ClusterNode::boot($system, $makeTopology($addrA, $endpointA, [$endpointB]), $makeTypes(), $transportA);

        for ($i = 0; $i < 200; ++$i) {
            if (count($nodeA->view()->upNodes()) === 2 && count($nodeB->view()->upNodes()) === 2) {
                break;
            }

            Coroutine::sleep(0.02);
        }

        // Sink (tell flood target) and responder (ask probe target), both on B.
        $sink = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$delivered): Behavior {
                    if ($msg instanceof Ping) {
                        ++$delivered;
                    }

                    return Behavior::same();
                },
            )),
            'soak-sink',
        );
        $nodeB->expose($sink);

        $responder = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof Ping) {
                        $ctx->sender()?->tell(new Pong($msg->text));
                    }

                    return Behavior::same();
                },
            )),
            'soak-responder',
        );
        $nodeB->expose($responder);

        $tellRef = $nodeA->refFor($addrB, $sink->path());
        $askRef = $nodeA->refFor($addrB, $responder->path());
        $payload = str_repeat('x', $payloadBytes);

        printf(
            "cluster-tcp soak — %d s, %d B payload, production topology defaults, msgpack ext: %s\n",
            $durationSeconds,
            $payloadBytes,
            extension_loaded('msgpack')
                ? 'yes'
                : 'no',
        );

        $deadlineNs = hrtime(true) + $durationSeconds * 1_000_000_000;
        $intervalStartNs = hrtime(true);
        $intervalStartDelivered = 0;
        $nextReportNs = $intervalStartNs + 10 * 1_000_000_000;
        $elapsedReported = 0;

        while (hrtime(true) < $deadlineNs) {
            // Blast a batch, then let the scheduler breathe.
            for ($i = 0; $i < 2_000; ++$i) {
                $tellRef->tell(new Ping($payload));
            }

            Coroutine::sleep(0.001);

            $nowNs = hrtime(true);

            if ($nowNs >= $nextReportNs) {
                // Ask probe: 50 sequential round-trips, report the median.
                $samples = [];

                for ($i = 0; $i < 50; ++$i) {
                    $t0 = hrtime(true);
                    $askRef->ask(new Ping('probe'), Duration::seconds(2))->await();
                    $samples[] = hrtime(true) - $t0;
                }

                sort($samples);
                $askP50Us = $samples[25] / 1_000.0;

                $elapsedReported += 10;
                $intervalDelivered = $delivered - $intervalStartDelivered;
                $intervalSeconds = ($nowNs - $intervalStartNs) / 1_000_000_000;
                $rate = $intervalDelivered / $intervalSeconds;
                $memMb = memory_get_usage(true) / 1_048_576;
                $peakMb = memory_get_peak_usage(true) / 1_048_576;

                printf(
                    "[t=%03ds] interval=%7s msg/s | total=%s | ask p50=%5.1f us | mem=%.1f MB (peak %.1f) | suspected=%d down=%d\n",
                    $elapsedReported,
                    number_format($rate),
                    number_format($delivered),
                    $askP50Us,
                    $memMb,
                    $peakMb,
                    $suspected,
                    $down,
                );

                $intervals[] = ['askP50Us' => $askP50Us, 'memMb' => $memMb, 'rate' => $rate, 't' => $elapsedReported];
                $intervalStartNs = $nowNs;
                $intervalStartDelivered = $delivered;
                $nextReportNs = $nowNs + 10 * 1_000_000_000;
            }
        }

        // Drain: wait until the sink has caught up with everything sent.
        for ($waited = 0.0; $waited < 30.0; $waited += 0.05) {
            $before = $delivered;
            Coroutine::sleep(0.05);

            if ($delivered === $before) {
                break;
            }
        }
    } finally {
        $nodeA?->shutdown();
        $nodeB?->shutdown();
        $transportA?->close();
        $transportB?->close();
        $system->shutdown(Duration::seconds(2));
    }
});

$system->run();

// ── Verdict ───────────────────────────────────────────────────────────────────

if (count($intervals) < 2) {
    echo "FAIL: not enough intervals collected\n";
    exit(1);
}

$first = $intervals[0];
$last = $intervals[count($intervals) - 1];
$mid = $intervals[intdiv(count($intervals), 2)];

if ($suspected > 0 || $down > 0) {
    $failReasons[] = "membership instability under load (suspected={$suspected}, down={$down})";
}

if ($last['rate'] < 0.7 * $first['rate']) {
    $failReasons[] = sprintf(
        'throughput decayed: first interval %.0f msg/s -> last %.0f msg/s',
        $first['rate'],
        $last['rate'],
    );
}

// Memory: compare steady state (mid) to end — early warm-up growth is expected.
if ($last['memMb'] > 1.5 * $mid['memMb'] + 8.0) {
    $failReasons[] = sprintf('memory grew: %.1f MB at midpoint -> %.1f MB at end', $mid['memMb'], $last['memMb']);
}

if ($last['askP50Us'] > 5 * $first['askP50Us']) {
    $failReasons[] = sprintf('ask latency degraded: p50 %.1f us -> %.1f us', $first['askP50Us'], $last['askP50Us']);
}

$rates = array_column($intervals, 'rate');
$meanRate = array_sum($rates) / count($rates);

printf(
    "\nSUMMARY: %s delivered over %d intervals | mean %s msg/s | mem %.1f -> %.1f MB | ask p50 %.1f -> %.1f us | suspected=%d down=%d\n",
    number_format($delivered),
    count($intervals),
    number_format($meanRate),
    $first['memMb'],
    $last['memMb'],
    $first['askP50Us'],
    $last['askP50Us'],
    $suspected,
    $down,
);

if ($failReasons !== []) {
    echo 'FAIL: ' . implode('; ', $failReasons) . "\n";
    exit(1);
}

echo "PASS: no membership instability, no throughput decay, flat memory, stable ask latency\n";
exit(0);
