<?php

declare(strict_types=1);

/**
 * Standalone cluster-tcp saturation worker.
 *
 * Boots ONE two-node cluster (both nodes in this process, over the real
 * SwooleMeshTransport on 127.0.0.1) and blasts N tells of a fixed payload from
 * node A to a sink on node B, then prints a single parseable result line:
 *
 *   RESULT msgs=<delivered> ms=<elapsed> msgps=<msg/s> mbps=<MB/s>
 *
 * The saturation driver (cluster_tcp_saturation.sh) launches K of these in
 * parallel inside the php-swoole container — each pins ~one core (one Swoole
 * reactor) — and sums msgps to show whole-machine aggregate throughput and CPU
 * saturation. Run directly:
 *
 *   docker compose exec php-swoole php tests/Performance/bin/cluster_tcp_bench_worker.php [count] [payloadBytes]
 */

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterNode;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Pong;
use Monadial\Nexus\Cluster\Tcp\Transport\Tcp\SwooleMeshTransport;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Serialization\TypeRegistry;
use Swoole\Coroutine;

require __DIR__ . '/../../../vendor/autoload.php';

$count = isset($argv[1])
    ? (int) $argv[1]
    : 100_000;
$payloadBytes = isset($argv[2])
    ? (int) $argv[2]
    : 1_024;

$runtime = new SwooleRuntime();
$system = ActorSystem::create('cluster-tcp-sat', $runtime);

$addrA = new NodeAddress('bench', 'local', 'nexus', 'node-a');
$addrB = new NodeAddress('bench', 'local', 'nexus', 'node-b');

$makeTopology = static function (NodeAddress $self, NodeEndpoint $endpoint, array $seeds): ClusterTopology {
    return ClusterTopology::create(
        clusterName: 'sat-cluster',
        self: $self,
        bindEndpoint: $endpoint,
        advertiseEndpoint: $endpoint,
        seeds: $seeds,
    )
        ->withHeartbeatInterval(Duration::millis(500))
        ->withGossipInterval(Duration::millis(500));
};

$makeTypes = static function (): TypeRegistry {
    $registry = new TypeRegistry();
    $registry->registerFromAttribute(Ping::class);
    $registry->registerFromAttribute(Pong::class);

    return $registry;
};

$elapsedMs = 0.0;
$delivered = 0;

$runtime->scheduleOnce(
    Duration::millis(1),
    static function () use ($runtime, $system, $addrA, $addrB, $makeTopology, $makeTypes, $count, $payloadBytes, &$elapsedMs, &$delivered): void {
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
                $makeTopology($addrB, $endpointB, [$endpointA]),
                $makeTypes(),
                $transportB,
            );
            $nodeA = ClusterNode::boot(
                $system,
                $makeTopology($addrA, $endpointA, [$endpointB]),
                $makeTypes(),
                $transportA,
            );

            for ($i = 0; $i < 200; ++$i) {
                if (count($nodeA->view()->upNodes()) === 2 && count($nodeB->view()->upNodes()) === 2) {
                    break;
                }

                Coroutine::sleep(0.02);
            }

            $sink = $system->spawn(
                Props::fromBehavior(Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$delivered): Behavior {
                        if ($msg instanceof Ping) {
                            ++$delivered;
                        }

                        return Behavior::same();
                    },
                )),
                'sat-sink',
            );
            $nodeB->expose($sink);

            $ref = $nodeA->refFor($addrB, $sink->path());
            $payload = str_repeat('x', $payloadBytes);

            // Warm-up.
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

            for ($waited = 0.0; $delivered < $count && $waited < 60.0; $waited += 0.005) {
                Coroutine::sleep(0.005);
            }

            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
        } finally {
            $nodeA?->shutdown();
            $nodeB?->shutdown();
            $transportA?->close();
            $transportB?->close();
            $system->shutdown(Duration::seconds(1));
        }
    },
);

$system->run();

$msgPerSec = $elapsedMs > 0.0
    ? $delivered / $elapsedMs * 1000.0
    : 0.0;
$mbPerSec = $elapsedMs > 0.0
    ? $delivered * $payloadBytes / $elapsedMs * 1000.0 / 1_048_576.0
    : 0.0;

printf("RESULT msgs=%d ms=%.1f msgps=%.0f mbps=%.1f\n", $delivered, $elapsedMs, $msgPerSec, $mbPerSec);
