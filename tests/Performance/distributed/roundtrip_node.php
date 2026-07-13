<?php

declare(strict_types=1);

/**
 * Distributed round-trip node — ONE cluster node per container. 16 containers form a full
 * mesh over real cross-container TCP; node 1 (the driver) sends `ask` round trips to node 16's
 * echo actor and node 16 replies with a Pong, so every completed round trip is a request +
 * reply over real TCP with MessagePack serialization.
 *
 * Unlike thread_mesh_node.php (W containers x T Swoole threads), this script runs ONE process =
 * ONE ActorSystem per container — no Swoole\Thread. That avoids the thread-pool teardown race
 * that can lose verdict lines, and makes one container == one mesh node.
 *
 * Environment:
 *   NODE_ID          1-based index of this node                       (required)
 *   NODES            number of nodes / containers                     (default 16)
 *   BASE_PORT        cluster TCP port (same on every container)       (default 7361)
 *   DURATION         load-window seconds                              (default 240)
 *   START_AT         shared unix start epoch (set by run-roundtrip.sh)
 *   ASK_CONCURRENCY  driver concurrent ask coroutines                 (default 64)
 *   TARGET_RPS       driver aggregate round-trip pacing target        (default 2000)
 *   PAYLOAD          Ping payload bytes                               (default 1024)
 *   OTEL_*           OpenTelemetry export (see buildObservability)
 *
 * Node N is reachable at hostname `worker{N}` (compose service name), port BASE_PORT.
 * Every node seeds to all other nodes (full mutual mesh). The driver targets the echo actor
 * exposed at the well-known name `rt-echo` (path /user/rt-echo) on node NODES.
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
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Observability\Actor\ActorSystemMetrics;
use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Otel\ObservabilityFactory;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Serialization\TypeRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Swoole\Coroutine;
use Swoole\Event;
use Swoole\Timer;

require __DIR__ . '/../../../vendor/autoload.php';

/** Minimal stderr logger so cluster-layer diagnostics are visible in container logs. */
final class MeshStderrLogger extends AbstractLogger
{
    public function __construct(private readonly string $tag) {}

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        fwrite(STDERR, sprintf("[%s %s] %s %s\n", $this->tag, $level, $message, json_encode($context)));
    }
}

/** Read an integer from the environment, falling back when unset, empty, or zero. */
function envInt(string $name, int $default): int
{
    $value = (int) getenv($name);

    return $value > 0
        ? $value
        : $default;
}

/** Read a string from the environment, falling back when unset or empty. */
function envStr(string $name, string $default): string
{
    $value = getenv($name);

    return $value === false || $value === ''
        ? $default
        : $value;
}

/**
 * Build a per-node OpenTelemetry provider when OTEL_EXPORTER_OTLP_ENDPOINT is set
 * (see run-roundtrip.sh + compose.roundtrip.yaml), otherwise a no-op. Each node exports
 * under one service (`nexus-cluster-roundtrip`) tagged with its own node.id resource
 * attribute, so Grafana/Tempo can slice traces per node (n1, n16, …).
 */
function buildObservability(string $tag, int $nodeId): Observability
{
    $endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT');

    if ($endpoint === false || $endpoint === '') {
        return new NoopObservability();
    }

    $config = ObservabilityConfig::fromEnv([
        'OTEL_EXPORTER_OTLP_ENDPOINT' => $endpoint,
        // Bound every OTLP HTTP call (milliseconds; nexus-observability-otel honors the
        // OTLP spec env and builds a stream-based, coroutine-cooperative transport). The
        // OTel default of 10 s equals the cluster's maxNoHeartbeat, so a stalled collector
        // could otherwise hold telemetry long enough to matter; 5 s keeps flushes well
        // inside the failure-detector window.
        'OTEL_EXPORTER_OTLP_TIMEOUT' => envStr('OTEL_EXPORTER_OTLP_TIMEOUT', '5000'),
        // Actorized async export toggle — must be forwarded into the hand-built env map,
        // or attachExportActor() below throws LogicException at boot.
        'OTEL_NEXUS_ASYNC_EXPORT' => envStr('OTEL_NEXUS_ASYNC_EXPORT', '0'),
        'OTEL_RESOURCE_ATTRIBUTES' => sprintf('node.id=%s,node.index=%d', $tag, $nodeId),
        'OTEL_SERVICE_NAME' => envStr('OTEL_SERVICE_NAME', 'nexus-cluster-roundtrip'),
        'OTEL_TRACES_SAMPLER' => envStr('OTEL_TRACES_SAMPLER', 'traceidratio'),
        'OTEL_TRACES_SAMPLER_ARG' => envStr('OTEL_TRACES_SAMPLER_ARG', '0.1'),
    ]);

    return ObservabilityFactory::fromConfig($config);
}

$nodeId = envInt('NODE_ID', 0);
$nodes = envInt('NODES', 16);
$basePort = envInt('BASE_PORT', 7361);
$duration = envInt('DURATION', 240);
$askConcurrency = envInt('ASK_CONCURRENCY', 64);
$targetRps = envInt('TARGET_RPS', 2_000);
$payloadBytes = envInt('PAYLOAD', 1_024);
$startAt = envInt('START_AT', time() + 20);

if ($nodeId < 1 || $nodeId > $nodes) {
    fwrite(STDERR, "NODE_ID must be 1..{$nodes}\n");
    exit(1);
}

$isDriver = $nodeId === 1;
$targetNodeId = $nodes; // node 1 drives round trips to the last node

printf(
    "[n%d] roundtrip node starting at unix=%d — %d-node mesh, %ds window, %d B payload%s\n",
    $nodeId,
    time(),
    $nodes,
    $duration,
    $payloadBytes,
    $isDriver
        ? sprintf(', DRIVER -> n%d, ask concurrency=%d, target=%d rt/s', $targetNodeId, $askConcurrency, $targetRps)
        : '',
);

$exitCode = runRoundtripNode(
    $nodeId,
    $nodes,
    $basePort,
    $duration,
    $askConcurrency,
    $targetRps,
    $payloadBytes,
    $startAt,
);

exit($exitCode);

// ── Node implementation ────────────────────────────────────────────────────────

function runRoundtripNode(
    int $nodeId,
    int $nodes,
    int $basePort,
    int $duration,
    int $askConcurrency,
    int $targetRps,
    int $payloadBytes,
    int $startAt,
): int {
    $tag = "n{$nodeId}";
    $isDriver = $nodeId === 1;
    $targetNodeId = $nodes;

    $observability = buildObservability($tag, $nodeId);
    $meshLogger = $observability instanceof OtelObservability
        ? $observability->psrLogger("roundtrip-{$tag}")
        : null;

    $suspected = 0;
    $down = 0;
    $byReason = ['Connection' => 0, 'Gossip' => 0, 'Phi' => 0];

    // MESH_EVENTS=1: print every suspicion/down event with a timestamp (diagnosis only — the
    // stderr volume is unsafe for verdict runs; see the MESH_DEBUG note below).
    $traceEvents = getenv('MESH_EVENTS') === '1';

    $dispatcher = new class ($suspected, $down, $byReason, $traceEvents, $tag) implements EventDispatcherInterface {
        /**
         * @param array<string, int> $byReason
         */
        public function __construct(
            private int &$suspected,
            private int &$down,
            private array &$byReason,
            private readonly bool $traceEvents,
            private readonly string $tag,
        ) {}

        public function dispatch(object $event): object
        {
            if ($event instanceof NodeSuspected) {
                ++$this->suspected;
                ++$this->byReason[$event->reason->name];

                if ($this->traceEvents) {
                    fwrite(STDERR, sprintf(
                        "[%s ev %.1f] SUSPECT %s reason=%s\n",
                        $this->tag,
                        microtime(true),
                        $event->node->node,
                        $event->reason->name,
                    ));
                }
            }

            if ($event instanceof NodeDown) {
                ++$this->down;

                if ($this->traceEvents) {
                    fwrite(STDERR, sprintf(
                        "[%s ev %.1f] DOWN %s\n",
                        $this->tag,
                        microtime(true),
                        $event->node->node,
                    ));
                }
            }

            return $event;
        }
    };

    // No hook-flag tuning needed: nexus-observability-otel builds its OTLP transports on
    // symfony's STREAM-based client, which avoids the userland curl shim's CURLOPT_SHARE
    // gap entirely and is coroutine-cooperative under SWOOLE_HOOK_ALL — exports yield to
    // the scheduler instead of freezing the reactor.
    $runtime = new SwooleRuntime();
    $system = ActorSystem::create(
        "roundtrip-{$tag}",
        $runtime,
        logger: $meshLogger,
        eventDispatcher: $dispatcher,
        observability: $observability,
    );

    if ($observability instanceof OtelObservability) {
        $actorMetrics = new ActorSystemMetrics($observability, $system);
        $actorMetrics->register();

        // Actorized async OTLP export (OTEL_NEXUS_ASYNC_EXPORT=1): all flush I/O moves off
        // application coroutines onto the 'otlp-export' actor's bounded mailbox.
        if (getenv('OTEL_NEXUS_ASYNC_EXPORT') === '1') {
            $observability->attachExportActor($system);
        }
    }

    $selfAddr = new NodeAddress('mesh', 'dc1', 'roundtrip', $tag);
    $bind = new NodeEndpoint(Host::of('0.0.0.0'), Port::of($basePort));
    $advertise = new NodeEndpoint(Host::of("worker{$nodeId}"), Port::of($basePort));

    /** @var list<NodeEndpoint> $seeds */
    $seeds = [];

    for ($n = 1; $n <= $nodes; ++$n) {
        if ($n === $nodeId) {
            continue;
        }

        $seeds[] = new NodeEndpoint(Host::of("worker{$n}"), Port::of($basePort));
    }

    $topology = ClusterTopology::create(
        clusterName: 'mesh-roundtrip',
        self: $selfAddr,
        bindEndpoint: $bind,
        advertiseEndpoint: $advertise,
        seeds: $seeds,
    )
        // Full-mutual mesh boot: every node dials every peer before most listeners are up,
        // so first dials fail. Capping backoff at 2 s converges the mesh promptly (default
        // 30 s max would trickle retries in for minutes). No phi tuning — validate the default.
        ->withReconnectBackoff(Duration::millis(200), Duration::seconds(2));

    $registry = new TypeRegistry();
    $registry->registerFromAttribute(Ping::class);
    $registry->registerFromAttribute(Pong::class);

    // Target: the echo actor exposed at /user/rt-echo on node $targetNodeId.
    $targetAddr = new NodeAddress('mesh', 'dc1', 'roundtrip', "n{$targetNodeId}");
    $echoPath = ActorPath::fromString('/user/rt-echo');

    /** @var list<NodeAddress> $peerAddrs all peers (for the liveness keepalive) */
    $peerAddrs = [];

    for ($n = 1; $n <= $nodes; ++$n) {
        if ($n === $nodeId) {
            continue;
        }

        $peerAddrs[] = new NodeAddress('mesh', 'dc1', 'roundtrip', "n{$n}");
    }

    $roundtrips = 0;
    $askFailures = 0;
    $converged = false;
    $convergedAtUnix = 0;
    $verdict = 'FAIL: did not run';
    // Bounded RTT reservoir (nanoseconds): a fixed-capacity ring buffer, NOT an unbounded
    // list — at full ask throughput an unbounded list exhausts the 128 MB process limit in
    // seconds, crashing the driver and (via its silence) storming the mesh's failure detector.
    // Percentiles over a rolling window of the most recent RTT_CAP samples are representative.
    $rttCap = 16_384;
    /** @var list<int> $rtts nanoseconds ring buffer (len <= $rttCap) */
    $rtts = [];
    $rttIdx = 0;

    $runtime->scheduleOnce(Duration::millis(1), static function () use (
        $runtime,
        $system,
        $topology,
        $registry,
        $observability,
        $meshLogger,
        $isDriver,
        $askConcurrency,
        $targetRps,
        $payloadBytes,
        $startAt,
        $duration,
        $nodes,
        $tag,
        $targetAddr,
        $echoPath,
        $peerAddrs,
        &$roundtrips,
        &$askFailures,
        &$converged,
        &$convergedAtUnix,
        &$verdict,
        &$rtts,
        $rttCap,
        &$rttIdx,
        &$suspected,
        &$down,
        &$byReason,
    ): void {
        $transport = null;
        $node = null;

        try {
            $transport = new SwooleMeshTransport($runtime);
            // Cluster boot logger is OPT-IN (MESH_DEBUG=1) only — NOT the OTLP PSR logger. The
            // cluster's PeerConnection emits debug logs on the connect/frame hot path; routing
            // those through the OTLP logs pipeline (a blocking, currently-failing curl export)
            // stalls the reactor and starves gossip. Traces (the deliverable) are unaffected.
            $bootLogger = getenv('MESH_DEBUG') === '1'
                ? new MeshStderrLogger($tag)
                : null;
            $node = ClusterNode::boot(
                $system,
                $topology,
                $registry,
                $transport,
                $observability,
                $bootLogger,
            );

            // Periodically push batched spans + the current metric snapshot to the collector so
            // Grafana shows a live time-series during the run (the manual reader only exports on
            // flush). With the curl hooks excluded above this is a blocking-but-working call of a
            // few ms against the local lgtm — cheap enough at a 10 s cadence.
            if ($observability instanceof OtelObservability) {
                $runtime->scheduleRepeatedly(
                    Duration::seconds(10),
                    Duration::seconds(10),
                    static function () use ($observability): void {
                        $observability->forceFlush();
                    },
                );
            }

            // Echo actor: reply to every Ping with a Pong to the sender (the ask reply path).
            $echo = $system->spawn(
                Props::fromBehavior(Behavior::receive(
                    static function (ActorContext $ctx, object $msg): Behavior {
                        if ($msg instanceof Ping) {
                            $ctx->sender()?->tell(new Pong($msg->text));
                        }

                        return Behavior::same();
                    },
                )),
                'rt-echo',
            );
            $node->expose($echo);

            // Converge: every node must see the full mesh Up before load starts.
            $expectedNodes = $nodes;
            $upCount = 0;
            $i = 0;

            while (microtime(true) < $startAt) {
                $upCount = count($node->view()->upNodes());

                if ($upCount === $expectedNodes) {
                    break;
                }

                if (++$i % 100 === 0) {
                    printf("[%s] converging... %d/%d Up at unix=%d\n", $tag, $upCount, $expectedNodes, time());
                }

                Coroutine::sleep(0.1);
            }

            $upCount = count($node->view()->upNodes());

            if ($upCount !== $expectedNodes) {
                $verdict = "FAIL: converged to {$upCount}/{$expectedNodes} nodes";

                return;
            }

            $converged = true;
            $convergedAtUnix = time();
            printf("[%s] converged: %d/%d nodes Up at unix=%d\n", $tag, $upCount, $expectedNodes, $convergedAtUnix);
            $meshLogger?->info('cluster converged', ['expected' => $expectedNodes, 'node' => $tag, 'up' => $upCount]);

            // Optional liveness keepalive (KEEPALIVE=1): one small tell per peer per second,
            // from EVERY node. Historically REQUIRED: with RandomPeerSelector, gossip fan-out
            // of 3 gave data-idle links an exponential heartbeat inter-arrival (~2.5 s mean),
            // so P(gap > maxNoHeartbeat 10 s) ≈ 2% per gap — a constant ~1.7 false
            // suspicions/s across the idle mesh's 240 links (measured; throughput- and
            // OTEL-independent). ShuffledCycleSelector now bounds the gap deterministically
            // (≤ ~5 s worst case at 16 nodes), so the default is OFF and this demo doubles
            // as the idle-mesh stability proof for that fix.
            if (getenv('KEEPALIVE') === '1') {
                $keepaliveRefs = [];

                foreach ($peerAddrs as $peerAddr) {
                    $keepaliveRefs[] = $node->refFor($peerAddr, $echoPath);
                }

                $runtime->scheduleRepeatedly(
                    Duration::seconds(1),
                    Duration::seconds(1),
                    static function () use ($keepaliveRefs): void {
                        foreach ($keepaliveRefs as $ref) {
                            try {
                                $ref->tell(new Ping('keepalive'));
                            } catch (Throwable) {
                                // A closed link drops the keepalive; membership handles the rest.
                            }
                        }
                    },
                );
            }

            // Synchronized load window: all nodes open and close together at START_AT + DURATION.
            $waitFor = $startAt - microtime(true);

            if ($waitFor > 0) {
                Coroutine::sleep($waitFor);
            }

            $windowRemaining = $startAt + $duration - microtime(true);

            if ($windowRemaining <= 5) {
                $verdict = 'FAIL: converged too late for the synchronized window';

                return;
            }

            $deadlineNs = hrtime(true) + (int) ($windowRemaining * 1_000_000_000);
            // Judgment snapshot point: 2 s BEFORE the shared deadline. At the deadline all 16
            // nodes exit within a poll-granularity skew (~250 ms) and the first to exit
            // broadcast graceful Leave frames, which (correctly) surface as NodeDown on nodes
            // still computing their verdict — orderly shutdown, not instability. Every node is
            // guaranteed alive at deadline-2s, so counters snapshotted there are race-free.
            $judgeNs = $deadlineNs - 2_000_000_000;
            $suspectedAtStart = $suspected;

            if (! $isDriver) {
                // Non-driver: idle (membership only). Health line + growth-gate verdict.
                $verdict = runIdleWindow(
                    $tag,
                    $deadlineNs,
                    $judgeNs,
                    $suspectedAtStart,
                    $suspected,
                    $down,
                );

                return;
            }

            // Driver: spawn ASK_CONCURRENCY coroutines pacing ask round trips at the target.
            // Pacing: each coroutine sleeps concurrency/TARGET_RPS between trips, so the
            // aggregate rate approaches TARGET_RPS (minus RTT overhead). An UNPACED ask flood
            // saturates the target node's reactor (asks queue, p99 explodes) and starves its
            // gossip heartbeats — the mesh then storms with false Suspect/Down via the
            // view-merge gossip echo. It also overflows the driver's OTLP span queue, dropping
            // the cluster.ask spans that chain the distributed trace.
            $pacingSleep = max(0.001, $askConcurrency / max(1, $targetRps));
            $payload = str_repeat('x', $payloadBytes);
            $intervalStartNs = hrtime(true);
            $intervalStartTrips = 0;
            $nextReportNs = $intervalStartNs + 30 * 1_000_000_000;
            $elapsed = 0;

            for ($c = 0; $c < $askConcurrency; ++$c) {
                $runtime->defer(static function () use (
                    $node,
                    $targetAddr,
                    $echoPath,
                    $payload,
                    $deadlineNs,
                    &$roundtrips,
                    &$askFailures,
                    &$rtts,
                    $rttCap,
                    &$rttIdx,
                    $pacingSleep,
                ): void {
                    $ref = $node->refFor($targetAddr, $echoPath);

                    while (hrtime(true) < $deadlineNs) {
                        $t0 = hrtime(true);

                        try {
                            $ref->ask(new Ping($payload), Duration::seconds(5))->await();
                            $rtt = hrtime(true) - $t0;
                            ++$roundtrips;
                            // Ring-buffer insert: overwrite the oldest slot once at capacity so
                            // the sample set stays bounded regardless of throughput.
                            $rtts[$rttIdx % $rttCap] = $rtt;
                            ++$rttIdx;
                        } catch (Throwable) {
                            ++$askFailures;
                        }

                        // Pace to the aggregate TARGET_RPS (see $pacingSleep above): keeps reactor
                        // headroom for gossip heartbeats on both the driver and the echo target.
                        Coroutine::sleep($pacingSleep);
                    }
                });
            }

            // Reporting loop: while the ask coroutines run, print a health line every 30 s and
            // take the race-free judgment snapshot at deadline-2s (see $judgeNs above).
            $suspectedUnderLoad = null;
            $downUnderLoad = null;
            $finalUp = null;

            while (hrtime(true) < $deadlineNs) {
                Coroutine::sleep(0.25);

                $nowNs = hrtime(true);

                if ($suspectedUnderLoad === null && $nowNs >= $judgeNs) {
                    $suspectedUnderLoad = $suspected;
                    $downUnderLoad = $down;
                    $finalUp = count($node->view()->upNodes());
                }

                if ($nowNs < $nextReportNs) {
                    continue;
                }

                $elapsed += 30;
                $intervalTrips = $roundtrips - $intervalStartTrips;
                $rate = $intervalTrips / (($nowNs - $intervalStartNs) / 1_000_000_000);
                [$p50, $p99] = percentiles($rtts);

                printf(
                    "[%s t=%03ds] roundtrips/s=%9s | total=%s | rtt p50=%8.1f us p99=%9.1f us | askFail=%d suspected=%d down=%d\n",
                    $tag,
                    $elapsed,
                    number_format($rate),
                    number_format($roundtrips),
                    $p50 / 1_000.0,
                    $p99 / 1_000.0,
                    $askFailures,
                    $suspected,
                    $down,
                );
                $meshLogger?->info('roundtrip health', [
                    'ask_failures' => $askFailures,
                    'down' => $down,
                    'elapsed_s' => $elapsed,
                    'node' => $tag,
                    'roundtrips' => $roundtrips,
                    'roundtrips_s' => (int) $rate,
                    'rtt_p50_us' => round($p50 / 1_000.0, 1),
                    'rtt_p99_us' => round($p99 / 1_000.0, 1),
                    'suspected' => $suspected,
                ]);

                $intervalStartNs = $nowNs;
                $intervalStartTrips = $roundtrips;
                $nextReportNs = $nowNs + 30 * 1_000_000_000;
            }

            // Judge on the pre-deadline snapshot; fall back to the current counters when the
            // window was too short for the snapshot point to be reached.
            $suspectedUnderLoad ??= $suspected;
            $downUnderLoad ??= $down;
            $finalUp ??= count($node->view()->upNodes());

            $reasons = [];

            if ($finalUp !== $expectedNodes) {
                $reasons[] = "view did not heal: {$finalUp}/{$expectedNodes} Up at end";
            }

            $steadyGrowth = $suspectedUnderLoad - $suspectedAtStart;

            if ($steadyGrowth > 20) {
                $reasons[] = sprintf(
                    'failure detector kept firing under load: suspicion grew +%d (%d->%d) [conn=%d gossip=%d phi=%d]',
                    $steadyGrowth,
                    $suspectedAtStart,
                    $suspectedUnderLoad,
                    $byReason['Connection'],
                    $byReason['Gossip'],
                    $byReason['Phi'],
                );
            }

            if ($roundtrips === 0) {
                $reasons[] = 'no round trips completed';
            }

            if ($askFailures > 0) {
                $reasons[] = "{$askFailures} ask round-trip failures";
            }

            if ($downUnderLoad > 0) {
                $reasons[] = "{$downUnderLoad} node(s) went down under load";
            }

            $verdict = $reasons === []
                ? 'PASS'
                : 'FAIL: ' . implode('; ', $reasons);

            // Driver summary line.
            $windowSecs = max(1, time() - ($startAt));
            [$p50, $p99] = percentiles($rtts);
            printf(
                "[%s] SUMMARY roundtrips=%s avg_roundtrips/s=%s rtt_p50=%.1f us rtt_p99=%.1f us\n",
                $tag,
                number_format($roundtrips),
                number_format((int) ($roundtrips / $windowSecs)),
                $p50 / 1_000.0,
                $p99 / 1_000.0,
            );
        } catch (Throwable $e) {
            $verdict = 'FAIL: ' . $e::class . ': ' . $e->getMessage();
        } finally {
            $node?->shutdown();
            $transport?->close();
            $system->shutdown(Duration::seconds(2));

            // Teardown failsafe: cluster shutdown can leak parked coroutines when there was
            // membership churn (pre-existing product bug — the 4x4 baseline run loses verdicts
            // to the same hang). Those coroutines never finish, Co\run never returns, and the
            // process hangs past the harness deadline with its verdict computed but unprinted.
            // Force the reactor down after a grace period; Swoole then prints a coroutine
            // deadlock report that doubles as the diagnostic for the underlying leak, run()
            // returns, and the normal verdict/exit path executes with the correct exit code.
            Timer::after(3_000, static function (): void {
                Event::exit();
            });
        }
    });

    $system->run();

    // Flush and stop the exporters before exit, or the final spans/metrics are lost.
    $observability->shutdown();

    printf(
        "[%s] %s | roundtrips=%s askFailures=%d converged=%s\n",
        $tag,
        $verdict,
        number_format($roundtrips),
        $askFailures,
        $converged
            ? 'yes'
            : 'no',
    );

    return $verdict === 'PASS'
        ? 0
        : 1;
}

/**
 * Non-driver idle window: no traffic, just a 30 s health line and a suspicion growth-gate verdict.
 * Judgment counters are snapshotted at `$judgeNs` (2 s before the shared deadline) so graceful
 * Leave frames from peers exiting marginally earlier are not miscounted as failures.
 */
function runIdleWindow(
    string $tag,
    int $deadlineNs,
    int $judgeNs,
    int $suspectedAtStart,
    int &$suspected,
    int &$down,
): string {
    $nextReportNs = hrtime(true) + 30 * 1_000_000_000;
    $elapsed = 0;
    $suspectedSnap = null;
    $downSnap = null;

    while (hrtime(true) < $deadlineNs) {
        Coroutine::sleep(0.25);

        if ($suspectedSnap === null && hrtime(true) >= $judgeNs) {
            $suspectedSnap = $suspected;
            $downSnap = $down;
        }

        if (hrtime(true) < $nextReportNs) {
            continue;
        }

        $elapsed += 30;
        printf("[%s t=%03ds] idle | suspected=%d down=%d\n", $tag, $elapsed, $suspected, $down);
        $nextReportNs = hrtime(true) + 30 * 1_000_000_000;
    }

    $suspectedSnap ??= $suspected;
    $downSnap ??= $down;

    $reasons = [];
    $steadyGrowth = $suspectedSnap - $suspectedAtStart;

    if ($steadyGrowth > 20) {
        $reasons[] = sprintf('suspicion grew +%d (%d->%d)', $steadyGrowth, $suspectedAtStart, $suspectedSnap);
    }

    if ($downSnap > 0) {
        $reasons[] = "{$downSnap} node(s) went down under load";
    }

    return $reasons === []
        ? 'PASS'
        : 'FAIL: ' . implode('; ', $reasons);
}

/**
 * p50 / p99 of an unsorted list of nanosecond samples. Returns [0.0, 0.0] when empty.
 *
 * @param list<int> $samples
 * @return array{0: float, 1: float}
 */
function percentiles(array $samples): array
{
    if ($samples === []) {
        return [0.0, 0.0];
    }

    $sorted = $samples;
    sort($sorted);
    $n = count($sorted);
    $p50 = (float) $sorted[(int) floor(0.50 * ($n - 1))];
    $p99 = (float) $sorted[(int) floor(0.99 * ($n - 1))];

    return [$p50, $p99];
}
