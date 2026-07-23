<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\ClusterTcp;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterNode;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeUp;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerConnected;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerDisconnected;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingEventDispatcher;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingLogger;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingObservability;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\SpyTracer;
use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackHub;
use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackMeshTransport;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end telemetry integration tests proving that C1.7a (tracing) + C1.7b (metrics)
 * + C1.7c (logs + peer lifecycle events) all fire correctly on the real loopback boot path.
 *
 * All nodes share one FiberRuntime, one ActorSystem, and one LoopbackHub so that fibers
 * are scheduled cooperatively in a single thread. A recording PSR-14 dispatcher is wired
 * into the ActorSystem so that both MembershipEvents and peer lifecycle events (PeerConnected,
 * PeerDisconnected) are captured. A shared RecordingObservability accumulates metrics and
 * spans from all nodes; a RecordingLogger captures PSR-3 structured logs.
 *
 * Port range: 9301–9320 (distinct from ClusterNodeTest's 9201–9270).
 *
 * Timer discipline: all timers are at absolute offsets before $system->run() — no nesting.
 */
#[CoversClass(ClusterNode::class)]
final class ClusterTelemetryTest extends TestCase
{
    private const int PORT_A = 9301;
    private const int PORT_B = 9302;

    private FiberRuntime $runtime;
    private LoopbackHub $hub;

    /**
     * Happy-path telemetry: boot a two-node loopback cluster, tell a Ping from A to B,
     * and assert the full expected telemetry set fires end-to-end:
     *   - PeerConnected PSR-14 event (C1.7c — TCP-link lifecycle)
     *   - NodeUp PSR-14 event (membership-view transition, existing C1.6 path)
     *   - cluster.handshake span (C1.7a tracing)
     *   - messages.sent + messages.received counters ≥ 1 (C1.7b metrics)
     *   - info-level log for cluster.membership.node_up (C1.7c logging)
     */
    #[Test]
    public function happyPathTelemetryFiresExpectedSignals(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $spy = new SpyTracer();
        $obs = new RecordingObservability($spy);
        $logger = new RecordingLogger();

        $system = ActorSystem::create('cluster-telemetry-happy', $this->runtime, eventDispatcher: $dispatcher);

        $addrA = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('test', 'local', 'nexus', 'node-b');
        $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A));
        $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_B));

        /** @var list<Ping> $receivedOnB */
        $receivedOnB = [];

        $pingRef = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(
                    static function ($ctx, object $msg) use (&$receivedOnB): Behavior {
                        if ($msg instanceof Ping) {
                            $receivedOnB[] = $msg;
                        }

                        return Behavior::same();
                    },
                ),
            ),
            'telemetry-ping-recv',
        );

        // Boot B first so it is already serving when A dials it.
        $nodeB = ClusterNode::boot(
            $system,
            $this->fastTopology('telemetry-cluster', $addrB, $endpointB, [$endpointA]),
            $this->makeUserTypes(),
            new LoopbackMeshTransport($this->hub, $this->runtime),
            observability: $obs,
            logger: $logger,
        );
        $nodeB->expose($pingRef);

        $nodeA = ClusterNode::boot(
            $system,
            $this->fastTopology('telemetry-cluster', $addrA, $endpointA, [$endpointB], singleNode: true),
            $this->makeUserTypes(),
            new LoopbackMeshTransport($this->hub, $this->runtime),
            observability: $obs,
            logger: $logger,
        );

        $pingPath = $pingRef->path();

        // T=600ms: handshake + first gossip round complete — tell a Ping from A to B.
        $this->runtime->scheduleOnce(
            Duration::millis(600),
            static function () use ($nodeA, $addrB, $pingPath): void {
                $nodeA->refFor($addrB, $pingPath)->tell(new Ping('telemetry-hello'));
            },
        );

        // T=1500ms: shutdown.
        $this->runtime->scheduleOnce(Duration::millis(1500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // --- C1.7c: PeerConnected PSR-14 event ---
        self::assertNotEmpty(
            $dispatcher->ofType(PeerConnected::class),
            'PeerConnected event should be dispatched when handshake completes',
        );

        // --- Existing C1.6: NodeUp PSR-14 event ---
        self::assertNotEmpty(
            $dispatcher->ofType(NodeUp::class),
            'NodeUp event should be dispatched after successful handshake',
        );

        // --- C1.7a: handshake span recorded ---
        self::assertNotEmpty(
            $spy->spansNamed('cluster.handshake'),
            'cluster.handshake span should be recorded on connect',
        );

        // --- C1.7b: send + receive counters ---
        self::assertGreaterThanOrEqual(
            1,
            $obs->meter->counterSum('nexus.cluster.messages.sent'),
            'nexus.cluster.messages.sent should be ≥ 1 after tell',
        );
        self::assertGreaterThanOrEqual(
            1,
            $obs->meter->counterSum('nexus.cluster.messages.received'),
            'nexus.cluster.messages.received should be ≥ 1 after tell',
        );

        // --- C1.7c: info log for NodeUp membership transition ---
        $nodeUpLogs = array_filter(
            $logger->logsAtLevel('info'),
            static fn(array $e): bool => $e['message'] === 'cluster.membership.node_up',
        );
        self::assertNotEmpty($nodeUpLogs, 'An info log for cluster.membership.node_up should be recorded');

        // --- Message delivery confirmed ---
        self::assertNotEmpty($receivedOnB, 'Ping should be delivered from A to B');
    }

    // -------------------------------------------------------------------------
    // Telemetry scenario 2: failure path — peer disconnect signals
    // -------------------------------------------------------------------------

    /**
     * Failure-path telemetry: boot two nodes, wait for convergence, shut down node B
     * (graceful leave), and assert the failure telemetry fires:
     *   - NodeDown PSR-14 event (membership-view transition from LeaveReceived)
     *   - PeerDisconnected PSR-14 event (C1.7c — TCP-link lifecycle)
     *   - nodes.pruned counter ≥ 1 (C1.7b metric)
     *   - info-level log for cluster.membership.node_down (C1.7c logging)
     *
     * Note: NodeSuspected does NOT fire on the graceful-leave path because the Leave
     * frame triggers applyLeave → NodeDown directly, before phi-accrual can run.
     * The phi-accrual Suspect→Down path requires heartbeats to stop and is non-deterministic
     * in the loopback runtime; it is exercised at the unit level via MembershipActorTest.
     */
    #[Test]
    public function failurePathTelemetryFiresOnPeerDisconnect(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $spy = new SpyTracer();
        $obs = new RecordingObservability($spy);
        $logger = new RecordingLogger();

        $system = ActorSystem::create('cluster-telemetry-fail', $this->runtime, eventDispatcher: $dispatcher);

        $addrA = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('test', 'local', 'nexus', 'node-b');
        $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A + 10));
        $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_B + 10));

        // Boot A as singleNode seed; B dials A.
        ClusterNode::boot(
            $system,
            $this->fastTopology('fail-cluster', $addrA, $endpointA, [], singleNode: true),
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
            observability: $obs,
            logger: $logger,
        );
        $nodeB = ClusterNode::boot(
            $system,
            $this->fastTopology('fail-cluster', $addrB, $endpointB, [$endpointA]),
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
            observability: $obs,
            logger: $logger,
        );

        // T=600ms: handshake + gossip round done — B shuts down gracefully (sends Leave).
        $this->runtime->scheduleOnce(
            Duration::millis(600),
            static function () use ($nodeB): void {
                $nodeB->shutdown();
            },
        );

        // T=1500ms: shutdown the actor system.
        $this->runtime->scheduleOnce(Duration::millis(1500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // --- NodeDown from LeaveReceived path ---
        self::assertNotEmpty(
            $dispatcher->ofType(NodeDown::class),
            'NodeDown event should be dispatched when peer sends Leave',
        );

        // --- C1.7c: PeerDisconnected from TCP link close ---
        self::assertNotEmpty(
            $dispatcher->ofType(PeerDisconnected::class),
            'PeerDisconnected event should be dispatched when peer link closes',
        );

        // --- C1.7b: nodes.pruned metric ---
        self::assertGreaterThanOrEqual(
            1,
            $obs->meter->counterSum('nexus.cluster.nodes.pruned'),
            'nexus.cluster.nodes.pruned counter should be ≥ 1 after peer leaves',
        );

        // --- C1.7c: info log for NodeDown ---
        $nodeDownLogs = array_filter(
            $logger->logsAtLevel('info'),
            static fn(array $e): bool => $e['message'] === 'cluster.membership.node_down',
        );
        self::assertNotEmpty($nodeDownLogs, 'An info log for cluster.membership.node_down should be recorded');
    }

    protected function setUp(): void
    {
        $this->runtime = new FiberRuntime();
        $this->hub = new LoopbackHub();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a ClusterTopology with fast heartbeat/gossip for test convergence.
     *
     * @param list<NodeEndpoint> $seeds
     */
    private function fastTopology(
        string $clusterName,
        NodeAddress $self,
        NodeEndpoint $endpoint,
        array $seeds,
        bool $singleNode = false,
    ): ClusterTopology {
        return ClusterTopology::create(
            clusterName: $clusterName,
            self: $self,
            bindEndpoint: $endpoint,
            advertiseEndpoint: $endpoint,
            seeds: $seeds,
            singleNode: $singleNode,
        )
            ->withHeartbeatInterval(Duration::millis(100))
            ->withGossipInterval(Duration::millis(100));
    }

    /**
     * Build a TypeRegistry pre-populated with the Ping fixture type.
     * Each call returns a fresh instance (boot() mutates the registry in-place).
     */
    private function makeUserTypes(): TypeRegistry
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Ping::class);

        return $registry;
    }
}
