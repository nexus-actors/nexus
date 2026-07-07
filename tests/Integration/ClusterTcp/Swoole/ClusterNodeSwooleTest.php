<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\ClusterTcp\Swoole;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterNode;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Swoole\SwooleMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Swoole\SwoolePeerLink;
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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Coroutine;

use function count;
use function in_array;

/**
 * Swoole real-socket integration tests for {@see ClusterNode}: two nodes form a
 * cluster over genuine TCP sockets (no in-process loopback hub).
 *
 * Termination discipline (HANG-AVOIDANCE):
 *   - Each test owns a fresh SwooleRuntime + ActorSystem and two SwooleMeshTransports.
 *   - Ports are OS-assigned via `bindEphemeral()` — no fixed ports, no cross-test collision.
 *   - All node boot + wire interaction happens inside a single `scheduleOnce()`
 *     coroutine (Swoole timer callbacks run in coroutine context, required for
 *     `Swoole\Coroutine\Client::connect()`).
 *   - Every wait is a BOUNDED poll loop (`for` + `Coroutine::sleep`), never `while (true)`.
 *   - A `finally` block shuts both nodes and both transports down and then the
 *     ActorSystem, so every server accept-loop and receive-loop coroutine drains
 *     before `$system->run()` (Co\run) returns — even if setup throws.
 *   - Assertions run AFTER `$system->run()` returns, on captured outer-scope values,
 *     so a failed expectation is never swallowed inside a coroutine.
 */
#[CoversClass(ClusterNode::class)]
#[CoversClass(SwooleMeshTransport::class)]
#[CoversClass(SwoolePeerLink::class)]
final class ClusterNodeSwooleTest extends TestCase
{
    /**
     * SCENARIO 1: Boot + join. Two nodes seed each other; both views converge to
     * two Up members over real TCP.
     */
    #[Test]
    public function twoNodesJoinAndConvergeOverRealTcp(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-swoole-join', $runtime);

        $membersA = 0;
        $upA = 0;
        $membersB = 0;
        $upB = 0;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, &$membersA, &$upA, &$membersB, &$upB): void {
                $transportA = null;
                $transportB = null;
                $nodeA = null;
                $nodeB = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);

                    $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');
                    $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');

                    $nodeB = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrB, $endpointB, [$endpointA]),
                        $this->makeUserTypes(),
                        $transportB,
                    );
                    $nodeA = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrA, $endpointA, [$endpointB]),
                        $this->makeUserTypes(),
                        $transportA,
                    );

                    $this->pollUntil(60, static function () use ($nodeA, $nodeB): bool {
                        return count($nodeA->view()->members) === 2
                            && count($nodeB->view()->members) === 2;
                    });

                    $viewA = $nodeA->view();
                    $viewB = $nodeB->view();
                    $membersA = count($viewA->members);
                    $upA = count($viewA->upNodes());
                    $membersB = count($viewB->members);
                    $upB = count($viewB->upNodes());
                } finally {
                    $this->cleanupCluster($system, $nodeA, $nodeB, $transportA, $transportB);
                }
            },
        );

        $system->run();

        self::assertSame(2, $membersA, 'Node A should see 2 members after convergence');
        self::assertSame(2, $upA, 'Node A should see both members Up');
        self::assertSame(2, $membersB, 'Node B should see 2 members after convergence');
        self::assertSame(2, $upB, 'Node B should see both members Up');
    }

    /**
     * SCENARIO 2: tell A→B over a real socket. An actor exposed on B receives the
     * exact message A sent via `refFor(B, path)->tell(...)`.
     */
    #[Test]
    public function tellFromAToBOverRealSocket(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-swoole-tell', $runtime);

        /** @var list<string> $received */
        $received = [];

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, &$received): void {
                $transportA = null;
                $transportB = null;
                $nodeA = null;
                $nodeB = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);

                    $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');
                    $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');

                    $nodeB = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrB, $endpointB, [$endpointA]),
                        $this->makeUserTypes(),
                        $transportB,
                    );
                    $nodeA = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrA, $endpointA, [$endpointB]),
                        $this->makeUserTypes(),
                        $transportA,
                    );

                    $behavior = Behavior::receive(
                        static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                            if ($msg instanceof Ping) {
                                $received[] = $msg->text;
                            }

                            return Behavior::same();
                        },
                    );
                    $ref = $system->spawn(Props::fromBehavior($behavior), 'ping-receiver');
                    $nodeB->expose($ref);

                    $this->pollUntil(60, static function () use ($nodeA, $nodeB): bool {
                        return count($nodeA->view()->members) === 2
                            && count($nodeB->view()->members) === 2;
                    });

                    $nodeA->refFor($addrB, $ref->path())->tell(new Ping('hello-over-tcp'));

                    $this->pollUntil(60, static function () use (&$received): bool {
                        return $received !== [];
                    });
                } finally {
                    $this->cleanupCluster($system, $nodeA, $nodeB, $transportA, $transportB);
                }
            },
        );

        $system->run();

        self::assertSame(['hello-over-tcp'], $received, 'B should receive exactly the Ping A sent over TCP');
    }

    /**
     * SCENARIO 3: ask A→B over a real socket. A asks an actor on B and awaits the
     * Pong reply frame routed back over TCP.
     */
    #[Test]
    public function askFromAToBOverRealSocket(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-swoole-ask', $runtime);

        /** @var Pong|null $captured */
        $captured = null;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, &$captured): void {
                $transportA = null;
                $transportB = null;
                $nodeA = null;
                $nodeB = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);

                    $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');
                    $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');

                    $nodeB = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrB, $endpointB, [$endpointA]),
                        $this->makeUserTypes(),
                        $transportB,
                    );
                    $nodeA = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrA, $endpointA, [$endpointB]),
                        $this->makeUserTypes(),
                        $transportA,
                    );

                    $pongBehavior = Behavior::receive(
                        static function (ActorContext $ctx, object $msg): Behavior {
                            if ($msg instanceof Ping) {
                                $ctx->sender()?->tell(new Pong($msg->text));
                            }

                            return Behavior::same();
                        },
                    );
                    $pongRef = $system->spawn(Props::fromBehavior($pongBehavior), 'pong-actor');
                    $nodeB->expose($pongRef);
                    $pongPath = $pongRef->path();

                    $askerBehavior = Behavior::receive(
                        static function (ActorContext $ctx, object $msg) use (
                            &$captured,
                            $nodeA,
                            $addrB,
                            $pongPath,
                        ): Behavior {
                            if ($msg instanceof Ping) {
                                $reply = $nodeA->refFor($addrB, $pongPath)
                                    ->ask(new Ping('ask-over-tcp'), Duration::seconds(2))
                                    ->await();

                                if ($reply instanceof Pong) {
                                    $captured = $reply;
                                }
                            }

                            return Behavior::same();
                        },
                    );
                    $askerRef = $system->spawn(Props::fromBehavior($askerBehavior), 'asker-actor');

                    $this->pollUntil(60, static function () use ($nodeA, $nodeB): bool {
                        return count($nodeA->view()->members) === 2
                            && count($nodeB->view()->members) === 2;
                    });

                    $askerRef->tell(new Ping('go'));

                    $this->pollUntil(80, static function () use (&$captured): bool {
                        return $captured !== null;
                    });
                } finally {
                    $this->cleanupCluster($system, $nodeA, $nodeB, $transportA, $transportB);
                }
            },
        );

        $system->run();

        self::assertInstanceOf(Pong::class, $captured, 'A should receive a Pong reply from B over TCP');
        self::assertSame('ask-over-tcp', $captured->text);
    }

    /**
     * SCENARIO 4: kill node → Suspect → Down. With a short failure-detection window
     * (maxNoHeartbeat 500ms), hard-closing B's transport makes A's accepted link
     * observe EOF, mark B Suspect, then declare it Down within a bounded window.
     */
    #[Test]
    public function killedNodeIsMarkedSuspectThenDown(): void
    {
        $runtime = new SwooleRuntime();
        $events = new RecordingMembershipDispatcher();
        $system = ActorSystem::create('cluster-swoole-kill', $runtime, eventDispatcher: $events);

        $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');
        $bPrefix = $addrB->toPathPrefix();

        $sawSuspect = false;
        $sawDown = false;
        $membersAfterDown = 2;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use (
                $runtime,
                $system,
                $events,
                $addrB,
                $bPrefix,
                &$sawSuspect,
                &$sawDown,
                &$membersAfterDown,
            ): void {
                $transportA = null;
                $transportB = null;
                $nodeA = null;
                $nodeB = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);

                    $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');

                    // A seeds B. B dials A, so A holds an accepted inbound link from B
                    // whose EOF signals B's death.
                    $topologyA = $this->fastTopology('swoole-cluster', $addrA, $endpointA, [$endpointB])
                        ->withFailureDetection(minStdDev: Duration::millis(50), maxNoHeartbeat: Duration::millis(500));
                    $topologyB = $this->fastTopology('swoole-cluster', $addrB, $endpointB, [$endpointA])
                        ->withFailureDetection(minStdDev: Duration::millis(50), maxNoHeartbeat: Duration::millis(500));

                    $nodeB = ClusterNode::boot($system, $topologyB, $this->makeUserTypes(), $transportB);
                    $nodeA = ClusterNode::boot($system, $topologyA, $this->makeUserTypes(), $transportA);

                    $this->pollUntil(60, static function () use ($nodeA): bool {
                        return count($nodeA->view()->members) === 2;
                    });

                    // Hard kill: close B's transport (sockets + servers) without a
                    // graceful Leave, simulating a crashed node.
                    $transportB->close();

                    // A must Suspect then Down B, dropping it from the view.
                    $this->pollUntil(100, static function () use ($nodeA, $addrB): bool {
                        return !$nodeA->view()->has($addrB);
                    });

                    $sawSuspect = $events->hasSuspected($bPrefix);
                    $sawDown = $events->hasDown($bPrefix);
                    $membersAfterDown = count($nodeA->view()->members);
                } finally {
                    $this->cleanupCluster($system, $nodeA, $nodeB, $transportA, $transportB);
                }
            },
        );

        $system->run();

        self::assertTrue($sawSuspect, 'A should have marked B Suspect after the link dropped');
        self::assertTrue($sawDown, 'A should have declared B Down after the give-up window');
        self::assertSame(1, $membersAfterDown, 'A should be left with only itself after B is Down');
    }

    /**
     * SCENARIO 5: handshake rejection. Two nodes configured with DIFFERENT cluster
     * names never admit each other — the peer is rejected and never added to a view.
     */
    #[Test]
    public function handshakeIsRejectedOnClusterNameMismatch(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-swoole-reject', $runtime);

        $membersA = 0;
        $membersB = 0;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, &$membersA, &$membersB): void {
                $transportA = null;
                $transportB = null;
                $nodeA = null;
                $nodeB = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);

                    $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');
                    $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');

                    // Distinct cluster names — B dials A, A rejects the handshake.
                    $nodeA = ClusterNode::boot(
                        $system,
                        $this->fastTopology('cluster-alpha', $addrA, $endpointA, [], singleNode: true),
                        $this->makeUserTypes(),
                        $transportA,
                    );
                    $nodeB = ClusterNode::boot(
                        $system,
                        $this->fastTopology('cluster-beta', $addrB, $endpointB, [$endpointA]),
                        $this->makeUserTypes(),
                        $transportB,
                    );

                    // No convergence is possible; give gossip ample time to (not) admit
                    // the peer, then assert both views still hold only self.
                    Coroutine::sleep(1.5);

                    $membersA = count($nodeA->view()->members);
                    $membersB = count($nodeB->view()->members);
                } finally {
                    $this->cleanupCluster($system, $nodeA, $nodeB, $transportA, $transportB);
                }
            },
        );

        $system->run();

        self::assertSame(1, $membersA, 'A must not admit a peer from a different cluster');
        self::assertSame(1, $membersB, 'B must not admit a peer from a different cluster');
    }

    /**
     * Create a SwooleMeshTransport bound to an OS-assigned ephemeral port on
     * 127.0.0.1 and return it together with the resolved advertise endpoint.
     *
     * @return array{SwooleMeshTransport, NodeEndpoint}
     */
    private function bindTransport(SwooleRuntime $runtime): array
    {
        $transport = new SwooleMeshTransport($runtime);
        $port = $transport->bindEphemeral(Host::of('127.0.0.1'));
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($port));

        return [$transport, $endpoint];
    }

    /**
     * Poll a condition up to `$maxIterations` times, sleeping 50ms between checks.
     * Returns as soon as the condition holds; bounded so it can never hang.
     *
     * @param callable(): bool $condition
     */
    private function pollUntil(int $maxIterations, callable $condition): void
    {
        for ($i = 0; $i < $maxIterations; ++$i) {
            if ($condition()) {
                return;
            }

            Coroutine::sleep(0.05);
        }
    }

    /**
     * Shut down nodes, transports, and the ActorSystem so every coroutine drains
     * and Co\run returns. All calls are idempotent and null-safe.
     */
    private function cleanupCluster(
        ActorSystem $system,
        ?ClusterNode $nodeA,
        ?ClusterNode $nodeB,
        ?SwooleMeshTransport $transportA,
        ?SwooleMeshTransport $transportB,
    ): void {
        $nodeA?->shutdown();
        $nodeB?->shutdown();
        $transportA?->close();
        $transportB?->close();
        $system->shutdown(Duration::seconds(1));
    }

    /**
     * Build a TypeRegistry pre-populated with the Ping/Pong fixture types. Each
     * call returns a fresh instance (boot() mutates the registry in place).
     */
    private function makeUserTypes(): TypeRegistry
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Ping::class);
        $registry->registerFromAttribute(Pong::class);

        return $registry;
    }

    /**
     * Build a ClusterTopology with fast heartbeat/gossip and short reconnect
     * backoff for quick convergence in tests.
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
            ->withGossipInterval(Duration::millis(100))
            ->withReconnectBackoff(Duration::millis(50), Duration::millis(200));
    }
}

/**
 * Records membership events dispatched by the cluster nodes so a test can assert
 * that a peer transitioned Suspect → Down. Lookups use NodeAddress path prefixes.
 */
final class RecordingMembershipDispatcher implements EventDispatcherInterface
{
    /** @var list<string> */
    private array $suspected = [];

    /** @var list<string> */
    private array $down = [];

    #[Override]
    public function dispatch(object $event): object
    {
        if ($event instanceof NodeSuspected) {
            $this->suspected[] = $event->node->toPathPrefix();
        }

        if ($event instanceof NodeDown) {
            $this->down[] = $event->node->toPathPrefix();
        }

        return $event;
    }

    public function hasSuspected(string $prefix): bool
    {
        return in_array($prefix, $this->suspected, true);
    }

    public function hasDown(string $prefix): bool
    {
        return in_array($prefix, $this->down, true);
    }
}
