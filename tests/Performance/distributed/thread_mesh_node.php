<?php

declare(strict_types=1);

/**
 * Distributed mesh soak node — W containers x T threads = W*T cluster nodes in one
 * full mesh over real cross-container TCP.
 *
 * Dual-mode script:
 *  - MAIN mode (container entrypoint): reads env, spawns T Swoole\Thread instances of
 *    THIS file, joins them, and exits 0 only if every thread reported PASS.
 *  - THREAD mode (Swoole\Thread::getArguments() non-empty): boots one ActorSystem +
 *    ClusterNode bound to its own port, converges to the full mesh, then floods tells
 *    at rotating remote peers and probes ask latency, reporting a health line every
 *    30 s and a final PASS/FAIL verdict.
 *
 * Environment (main mode):
 *   WORKER_ID  1-based index of this container            (required)
 *   WORKERS    number of containers                       (default 4)
 *   THREADS    cluster nodes per container                (default 4)
 *   BASE_PORT  first TCP port per container               (default 7361)
 *   DURATION   soak seconds                               (default 300)
 *   PAYLOAD    tell payload bytes                         (default 1024)
 *
 * Every node seeds to all other nodes (full mutual mesh). Peer w{X}t{Y} is reachable
 * at hostname workerX (compose service name), port BASE_PORT+Y-1.
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
use Swoole\Thread;
use Swoole\Thread\Atomic;

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

$threadArgs = Thread::getArguments();

// ── THREAD mode: one cluster node ─────────────────────────────────────────────

if ($threadArgs !== null && $threadArgs !== []) {
    [$workerId, $threadId, $workers, $threads, $basePort, $duration, $payloadBytes, $startAt, $failCounter] = $threadArgs;

    runMeshNode(
        (int) $workerId,
        (int) $threadId,
        (int) $workers,
        (int) $threads,
        (int) $basePort,
        (int) $duration,
        (int) $payloadBytes,
        (int) $startAt,
        $failCounter,
    );

    return;
}

// ── MAIN mode: spawn T threads and aggregate ──────────────────────────────────

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
 * (see run-observability.sh + compose.observability.yaml), otherwise a no-op. Each node
 * exports under one service (`nexus-cluster-mesh`) tagged with its own node/worker/thread
 * resource attributes, so Grafana can slice cluster.* metrics and traces per node.
 */
function buildObservability(string $tag, int $workerId, int $threadId): Observability
{
    $endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT');

    if ($endpoint === false || $endpoint === '') {
        return new NoopObservability();
    }

    $config = ObservabilityConfig::fromEnv([
        'OTEL_EXPORTER_OTLP_ENDPOINT' => $endpoint,
        'OTEL_RESOURCE_ATTRIBUTES' => sprintf('node.id=%s,worker.id=%d,thread.id=%d', $tag, $workerId, $threadId),
        // Sample traces (the soak emits a cluster.send span per message); metrics are always
        // fully aggregated. Override via OTEL_TRACES_SAMPLER_ARG.
        'OTEL_SERVICE_NAME' => envStr('OTEL_SERVICE_NAME', 'nexus-cluster-mesh'),
        'OTEL_TRACES_SAMPLER' => envStr('OTEL_TRACES_SAMPLER', 'traceidratio'),
        'OTEL_TRACES_SAMPLER_ARG' => envStr('OTEL_TRACES_SAMPLER_ARG', '0.01'),
    ]);

    return ObservabilityFactory::fromConfig($config);
}

$workerId = envInt('WORKER_ID', 0);
$workers = envInt('WORKERS', 4);
$threads = envInt('THREADS', 4);
$basePort = envInt('BASE_PORT', 7361);
$duration = envInt('DURATION', 300);
$payloadBytes = envInt('PAYLOAD', 1_024);
$startAt = envInt('START_AT', time() + 20);

if ($workerId < 1 || $workerId > $workers) {
    fwrite(STDERR, "WORKER_ID must be 1..{$workers}\n");
    exit(1);
}

printf(
    "[w%d] mesh worker starting at unix=%d — %d threads, mesh %dx%d=%d nodes, %ds soak, %d B payload\n",
    $workerId,
    time(),
    $threads,
    $workers,
    $threads,
    $workers * $threads,
    $duration,
    $payloadBytes,
);

$failCounter = new Atomic(0);
$spawned = [];

for ($t = 1; $t <= $threads; ++$t) {
    $spawned[] = new Thread(
        __FILE__,
        $workerId,
        $t,
        $workers,
        $threads,
        $basePort,
        $duration,
        $payloadBytes,
        $startAt,
        $failCounter,
    );
}

foreach ($spawned as $thread) {
    $thread->join();
}

$fails = $failCounter->get();
printf("[w%d] worker done — %d/%d threads passed\n", $workerId, $threads - $fails, $threads);
exit($fails === 0 ? 0 : 1);

// ── Node implementation ───────────────────────────────────────────────────────

function runMeshNode(
    int $workerId,
    int $threadId,
    int $workers,
    int $threads,
    int $basePort,
    int $duration,
    int $payloadBytes,
    int $startAt,
    Atomic $failCounter,
): void {
    $tag = "w{$workerId}t{$threadId}";
    $expectedNodes = $workers * $threads;

    $observability = buildObservability($tag, $workerId, $threadId);
    // PSR-3 logger that exports over OTLP (to Loki) when observability is on, correlated with
    // the active trace. Null when disabled → the system/cluster use their own defaults.
    $meshLogger = $observability instanceof OtelObservability
        ? $observability->psrLogger("mesh-{$tag}")
        : null;
    // Tells per batch before yielding — lower it (SEND_BATCH) for a readable observability
    // demo so metrics/traces reflect a gentle rate rather than link saturation.
    $sendBatch = envInt('SEND_BATCH', 500);

    $suspected = 0;
    $down = 0;
    $byReason = ['Connection' => 0, 'Gossip' => 0, 'Phi' => 0];

    $dispatcher = new class ($suspected, $down, $byReason) implements EventDispatcherInterface {
        /**
         * @param array<string, int> $byReason
         */
        public function __construct(private int &$suspected, private int &$down, private array &$byReason) {}

        public function dispatch(object $event): object
        {
            if ($event instanceof NodeSuspected) {
                ++$this->suspected;
                ++$this->byReason[$event->reason->name];
            }

            if ($event instanceof NodeDown) {
                ++$this->down;
            }

            return $event;
        }
    };

    $runtime = new SwooleRuntime();
    // Wire observability into the ActorSystem so ActorCell emits a `process {type}` span and
    // nexus.actor.* metrics per message — this is what extends the trace chain past the cluster
    // boundary into the receiving actor, and lights up the actor rows of the dashboards.
    $system = ActorSystem::create(
        "mesh-{$tag}",
        $runtime,
        logger: $meshLogger,
        eventDispatcher: $dispatcher,
        observability: $observability,
    );

    // Emit ActorSystem gauges (live actors, dead letters, running) alongside the cluster
    // metrics, so the Grafana overview dashboard's "Actor system" row is live for this node.
    if ($observability instanceof OtelObservability) {
        $actorMetrics = new ActorSystemMetrics($observability, $system);
        $actorMetrics->register();
    }

    $selfPort = $basePort + $threadId - 1;
    $selfAddr = new NodeAddress('mesh', 'dc1', 'soak', $tag);
    $bind = new NodeEndpoint(Host::of('0.0.0.0'), Port::of($selfPort));
    $advertise = new NodeEndpoint(Host::of("worker{$workerId}"), Port::of($selfPort));

    /** @var list<NodeEndpoint> $seeds */
    $seeds = [];
    /** @var list<NodeAddress> $peerAddrs */
    $peerAddrs = [];

    for ($w = 1; $w <= $workers; ++$w) {
        for ($t = 1; $t <= $threads; ++$t) {
            if ($w === $workerId && $t === $threadId) {
                continue;
            }

            $seeds[] = new NodeEndpoint(Host::of("worker{$w}"), Port::of($basePort + $t - 1));
            $peerAddrs[] = new NodeAddress('mesh', 'dc1', 'soak', "w{$w}t{$t}");
        }
    }

    $topology = ClusterTopology::create(
        clusterName: 'mesh-soak',
        self: $selfAddr,
        bindEndpoint: $bind,
        advertiseEndpoint: $advertise,
        seeds: $seeds,
    )
        // Full-mutual mesh boot: every node dials every peer before most listeners are
        // up, so first dials fail. With the default 30 s max backoff those retries
        // trickle in for minutes; capping at 2 s converges the 16-node mesh promptly.
        ->withReconnectBackoff(Duration::millis(200), Duration::seconds(2))
        // Saturated-links tuning. MEASURED (full send rate, 16 nodes, 60 s): at pure
        // default phi the detector legitimately fires ~8-18 times/node on multi-second
        // reactor stalls caused by data-plane saturation, and each transient still spreads
        // through gossip — views ALWAYS heal (0 unhealed, 0 Downs, 39M msgs delivered), but
        // the event stream is noisy. Widening the std-dev floor to 3 s keeps detection
        // meaningful for real failures (Suspect->Down still 10 s) while tolerating that load
        // jitter, and the mesh passes at full send rate. The real fix is control/data-plane
        // connection separation (plan Phase 1.A), whose acceptance bar is passing here at
        // DEFAULT phi with this line removed.
        ->withFailureDetection(minStdDev: Duration::seconds(3));

    $registry = new TypeRegistry();
    $registry->registerFromAttribute(Ping::class);
    $registry->registerFromAttribute(Pong::class);

    $received = 0;
    $sent = 0;
    $askFailures = 0;
    $converged = false;
    $verdict = 'FAIL: did not run';
    /** @var list<float> $rates */
    $rates = [];
    /** @var list<float> $mems */
    $mems = [];

    $runtime->scheduleOnce(Duration::millis(1), static function () use (
        $runtime,
        $system,
        $topology,
        $registry,
        $observability,
        $meshLogger,
        $sendBatch,
        $peerAddrs,
        $expectedNodes,
        $duration,
        $payloadBytes,
        $startAt,
        $tag,
        &$received,
        &$sent,
        &$askFailures,
        &$converged,
        &$verdict,
        &$rates,
        &$mems,
        &$suspected,
        &$down,
        &$byReason,
    ): void {
        $transport = null;
        $node = null;

        try {
            $transport = new SwooleMeshTransport($runtime);
            // Cluster debug logging is OPT-IN (MESH_DEBUG=1): a blocking stderr logger on
            // the frame path stalls the whole thread when the container log pipe backs
            // up, starving gossip timers and causing the very suspicion it then logs.
            $bootLogger = $meshLogger ?? (getenv('MESH_DEBUG') === '1'
                ? new MeshStderrLogger($tag)
                : null);
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
            // flush). Swoole's coroutine hooks keep the OTLP HTTP export non-blocking.
            if ($observability instanceof OtelObservability) {
                $runtime->scheduleRepeatedly(
                    Duration::seconds(10),
                    Duration::seconds(10),
                    static function () use ($observability): void {
                        $observability->forceFlush();
                    },
                );
            }

            $sink = $system->spawn(
                Props::fromBehavior(Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                        if ($msg instanceof Ping) {
                            ++$received;
                        }

                        return Behavior::same();
                    },
                )),
                'mesh-sink',
            );
            $node->expose($sink);

            $echo = $system->spawn(
                Props::fromBehavior(Behavior::receive(
                    static function (ActorContext $ctx, object $msg): Behavior {
                        if ($msg instanceof Ping) {
                            $ctx->sender()?->tell(new Pong($msg->text));
                        }

                        return Behavior::same();
                    },
                )),
                'mesh-echo',
            );
            $node->expose($echo);

            // Converge: every node must see the full mesh Up before load starts.
            $upCount = 0;

            // Converge until the shared start epoch (containers cold-boot at very different
            // speeds through the bind mount — the margin must absorb the slowest).
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
            printf("[%s] converged: %d/%d nodes Up\n", $tag, $upCount, $expectedNodes);
            $meshLogger?->info('cluster converged', ['expected' => $expectedNodes, 'node' => $tag, 'up' => $upCount]);

            // Synchronized load window: every node starts at the shared START_AT epoch and
            // stops DURATION later (containers share the host clock). Without this, nodes
            // that converge early finish early and broadcast graceful Leaves into the
            // still-running windows of late convergers — indistinguishable from failures.
            $waitFor = $startAt - microtime(true);

            if ($waitFor > 0) {
                Coroutine::sleep($waitFor);
            }

            $windowRemaining = $startAt + $duration - microtime(true);

            if ($windowRemaining <= 5) {
                $verdict = 'FAIL: converged too late for the synchronized window';

                return;
            }

            $sinkPath = $sink->path();
            $echoPath = $echo->path();
            $payload = str_repeat('x', $payloadBytes);
            $peerCount = count($peerAddrs);

            $lastAskP50Us = -1.0;
            $deadlineNs = hrtime(true) + (int) ($windowRemaining * 1_000_000_000);
            $intervalStartNs = hrtime(true);
            $intervalStartReceived = 0;
            $nextReportNs = $intervalStartNs + 30 * 1_000_000_000;
            $elapsed = 0;
            $target = 0;

            while (hrtime(true) < $deadlineNs) {
                // Batch of tells to the next peer (rotates through the whole mesh).
                $ref = $node->refFor($peerAddrs[$target % $peerCount], $sinkPath);
                ++$target;

                for ($i = 0; $i < $sendBatch; ++$i) {
                    $ref->tell(new Ping($payload));
                    ++$sent;
                }

                // Yield once per batch so the reactor services reads/gossip; the mesh runs
                // at full send rate (headroom is not required for stability now that stale
                // suspicion is deduped and refutation is reachable).
                Coroutine::sleep(0.001);

                $nowNs = hrtime(true);

                if ($nowNs >= $nextReportNs) {
                    // Ask probe CONCURRENT with the flood (via Runtime::defer): pausing the
                    // tell loop for sequential asks would silence this node for seconds and
                    // train-then-trip the peers' phi detectors — a real app keeps talking
                    // while it asks. p50 reported from the last COMPLETED probe.
                    $askRef = $node->refFor($peerAddrs[$target % $peerCount], $echoPath);
                    $runtime->defer(static function () use ($askRef, &$lastAskP50Us, &$askFailures): void {
                        $samples = [];

                        for ($i = 0; $i < 20; ++$i) {
                            $t0 = hrtime(true);

                            try {
                                $askRef->ask(new Ping('probe'), Duration::seconds(5))->await();
                                $samples[] = hrtime(true) - $t0;
                            } catch (Throwable) {
                                ++$askFailures;
                            }
                        }

                        sort($samples);

                        if ($samples !== []) {
                            $lastAskP50Us = $samples[intdiv(count($samples), 2)] / 1_000.0;
                        }
                    });
                    $askP50Us = $lastAskP50Us;

                    $elapsed += 30;
                    $intervalReceived = $received - $intervalStartReceived;
                    $rate = $intervalReceived / (($nowNs - $intervalStartNs) / 1_000_000_000);
                    $memMb = memory_get_usage(true) / 1_048_576;
                    $rates[] = $rate;
                    $mems[] = $memMb;

                    printf(
                        "[%s t=%03ds] recv=%7s msg/s | sent=%s recv=%s | ask p50=%6.1f us | mem=%.1f MB | suspected=%d down=%d\n",
                        $tag,
                        $elapsed,
                        number_format($rate),
                        number_format($sent),
                        number_format($received),
                        $askP50Us,
                        $memMb,
                        $suspected,
                        $down,
                    );
                    $meshLogger?->info('mesh health', [
                        'down' => $down,
                        'elapsed_s' => $elapsed,
                        'mem_mb' => round($memMb, 1),
                        'node' => $tag,
                        'rate_msg_s' => (int) $rate,
                        'suspected' => $suspected,
                    ]);

                    $intervalStartNs = $nowNs;
                    $intervalStartReceived = $received;
                    $nextReportNs = $nowNs + 30 * 1_000_000_000;
                }
            }

            // Judge membership on the LOAD WINDOW only: once the deadline passes, other
            // nodes finish and broadcast graceful Leave frames, which (correctly) surface
            // as NodeDown on nodes still draining — that is orderly shutdown, not
            // instability. Snapshot before the drain.
            $suspectedUnderLoad = $suspected;
            $downUnderLoad = $down;
            $finalUp = count($node->view()->upNodes());

            for ($waited = 0.0; $waited < 20.0; $waited += 0.05) {
                $before = $received;
                Coroutine::sleep(0.05);

                if ($received === $before) {
                    break;
                }
            }

            $reasons = [];

            // With event dedup, each observer announces a cluster-wide suspicion episode at
            // most once per state change — transient, self-healing suspicion is expected in
            // an AP mesh under load. FAIL on an event STORM (echo regression) or on any
            // view that did not heal to full membership by the end of the window.
            if ($finalUp !== $expectedNodes) {
                $reasons[] = "view did not heal: {$finalUp}/{$expectedNodes} Up at end";
            }

            if ($suspectedUnderLoad > 30) {
                $reasons[] = sprintf(
                    'failure detector fired under load (suspected=%d [conn=%d gossip=%d phi=%d], down=%d)',
                    $suspectedUnderLoad,
                    $byReason['Connection'],
                    $byReason['Gossip'],
                    $byReason['Phi'],
                    $downUnderLoad,
                );
            }

            if ($sent === 0 || $received === 0) {
                $reasons[] = "no traffic (sent={$sent}, received={$received})";
            }

            if (count($rates) >= 2 && $rates[count($rates) - 1] < 0.5 * $rates[0]) {
                $reasons[] = sprintf('throughput decayed %.0f -> %.0f msg/s', $rates[0], $rates[count($rates) - 1]);
            }

            if (count($mems) >= 2 && $mems[count($mems) - 1] > 1.5 * $mems[0] + 8.0) {
                $reasons[] = sprintf('memory grew %.1f -> %.1f MB', $mems[0], $mems[count($mems) - 1]);
            }

            if ($askFailures > 10) {
                $reasons[] = "{$askFailures} ask probe failures";
            }

            $verdict = $reasons === []
                ? 'PASS'
                : 'FAIL: ' . implode('; ', $reasons);
        } catch (Throwable $e) {
            $verdict = 'FAIL: ' . $e::class . ': ' . $e->getMessage();
        } finally {
            $node?->shutdown();
            $transport?->close();
            $system->shutdown(Duration::seconds(2));
        }
    });

    $system->run();

    // Flush and stop the exporters before the thread exits, or the final spans/metrics are lost.
    // Runs after the reactor has stopped, so the OTLP export is a plain synchronous HTTP call.
    $observability->shutdown();

    printf(
        "[%s] %s | sent=%s received=%s askFailures=%d converged=%s\n",
        $tag,
        $verdict,
        number_format($sent),
        number_format($received),
        $askFailures,
        $converged
            ? 'yes'
            : 'no',
    );

    if ($verdict !== 'PASS') {
        $failCounter->add(1);
    }
}
