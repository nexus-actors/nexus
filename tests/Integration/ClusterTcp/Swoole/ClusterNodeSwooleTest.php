<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\ClusterTcp\Swoole;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterNode;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Pong;
use Monadial\Nexus\Cluster\Tcp\Transport\Tcp\SwooleMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Transport\Tcp\SwoolePeerLink;
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
     * Restart-rejoin regression: a node that gracefully leaves and then comes back with the
     * SAME identity must rejoin the cluster and re-converge with the long-lived seed.
     *
     * This is the documented recovery path ("restart the process to re-join") and it was broken:
     * the handshake used to be sent at most once per peer for the process lifetime, so the
     * surviving seed never re-introduced itself to a returning node and the two never re-formed
     * the mesh. The fix makes the handshake a per-connection preamble, so every (re)connect
     * re-announces identity. The seed A here is long-lived across B's departure and return, so
     * this exercises exactly the stale-identity path the old once-per-process handshake hit.
     */
    #[Test]
    public function departedNodeRejoinsWithSameIdentity(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-swoole-rejoin', $runtime);

        $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');

        $membersAfterLeave = 2;
        $membersAfterRejoin = 0;
        $mutualAfterRejoin = false;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, $addrA, $addrB, &$membersAfterLeave, &$membersAfterRejoin, &$mutualAfterRejoin): void {
                $transportA = null;
                $transportB = null;
                $transportB2 = null;
                $nodeA = null;
                $nodeB = null;
                $nodeB2 = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);

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

                    $this->pollUntil(80, static function () use ($nodeA, $nodeB): bool {
                        return count($nodeA->view()->members) === 2 && count($nodeB->view()->members) === 2;
                    });

                    // B departs gracefully; A must drop it back to a solo view.
                    $nodeB->shutdown();
                    $transportB->close();
                    $transportB = null;
                    $nodeB = null;

                    $this->pollUntil(120, static function () use ($nodeA, $addrB): bool {
                        return !$nodeA->view()->has($addrB);
                    });
                    $membersAfterLeave = count($nodeA->view()->members);

                    // A restarted node reuses B's identity on a fresh transport and dials the
                    // still-running A. With the per-connection handshake preamble, A re-identifies
                    // the returning node and both re-converge.
                    [$transportB2, $endpointB2] = $this->bindTransport($runtime);
                    $nodeB2 = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrB, $endpointB2, [$endpointA]),
                        $this->makeUserTypes(),
                        $transportB2,
                    );

                    $this->pollUntil(160, static function () use ($nodeA, $nodeB2): bool {
                        return count($nodeA->view()->members) === 2 && count($nodeB2->view()->members) === 2;
                    });

                    $membersAfterRejoin = count($nodeA->view()->members);
                    $mutualAfterRejoin = $nodeA->view()->has($addrB) && $nodeB2->view()->has($addrA);
                } finally {
                    $nodeA?->shutdown();
                    $nodeB?->shutdown();
                    $nodeB2?->shutdown();
                    $transportA?->close();
                    $transportB?->close();
                    $transportB2?->close();
                    $system->shutdown(Duration::seconds(1));
                }
            },
        );

        $system->run();

        self::assertSame(1, $membersAfterLeave, 'A should be alone after B gracefully leaves');
        self::assertSame(2, $membersAfterRejoin, 'A should see two members again after B restarts and rejoins');
        self::assertTrue($mutualAfterRejoin, 'A and the restarted B must see each other after rejoin');
    }

    /**
     * Transient-disconnect-then-heal: a TCP blip drops the links between two continuously-running,
     * healthy nodes; both must re-establish and re-converge WITHOUT restarting. This is the core
     * failure mode the per-connection handshake preamble targets — a reconnected link re-announces
     * identity so the remote re-identifies the peer, instead of dropping its post-reconnect frames
     * forever. Failure detection is tightened so that, absent the preamble, the un-re-handshaked
     * links would silence-detect each other into a permanent partition; with the fix they heal.
     */
    #[Test]
    public function transientLinkDropHealsWithoutRestart(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-swoole-heal', $runtime);

        $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');

        $convergedBefore = false;
        $healedAfter = false;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, $addrA, $addrB, &$convergedBefore, &$healedAfter): void {
                $transportA = null;
                $transportB = null;
                $nodeA = null;
                $nodeB = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);

                    $topoB = $this->fastTopology('swoole-cluster', $addrB, $endpointB, [$endpointA])
                        ->withFailureDetection(minStdDev: Duration::millis(50), maxNoHeartbeat: Duration::millis(600));
                    $topoA = $this->fastTopology('swoole-cluster', $addrA, $endpointA, [$endpointB])
                        ->withFailureDetection(minStdDev: Duration::millis(50), maxNoHeartbeat: Duration::millis(600));

                    $nodeB = ClusterNode::boot($system, $topoB, $this->makeUserTypes(), $transportB);
                    $nodeA = ClusterNode::boot($system, $topoA, $this->makeUserTypes(), $transportA);

                    $this->pollUntil(80, static function () use ($nodeA, $nodeB): bool {
                        return count($nodeA->view()->members) === 2 && count($nodeB->view()->members) === 2;
                    });
                    $convergedBefore = count($nodeA->view()->members) === 2 && count($nodeB->view()->members) === 2;

                    // Transient blip: drop the accepted links on both ends (servers stay up). Both
                    // nodes keep running; their outbound PeerConnections must reconnect + re-handshake.
                    $transportA->dropServerLinksForTest();
                    $transportB->dropServerLinksForTest();

                    // The mesh must re-converge on its own — even if the tight give-up window briefly
                    // Downs the peer, the reconnect + preamble re-handshake brings it back to Up.
                    $this->pollUntil(200, static function () use ($nodeA, $nodeB, $addrA, $addrB): bool {
                        return count($nodeA->view()->members) === 2
                            && count($nodeB->view()->members) === 2
                            && $nodeA->view()->has($addrB)
                            && $nodeB->view()->has($addrA);
                    });
                    $healedAfter = count($nodeA->view()->members) === 2 && count($nodeB->view()->members) === 2;
                } finally {
                    $this->cleanupCluster($system, $nodeA, $nodeB, $transportA, $transportB);
                }
            },
        );

        $system->run();

        self::assertTrue($convergedBefore, 'A and B must converge to two members before the link drop');
        self::assertTrue(
            $healedAfter,
            'A and B must re-converge to two members after a transient link drop, without restarting',
        );
    }

    /**
     * Multi-node (3) convergence + failure: three nodes form a mesh via gossip (B and C seed only A,
     * yet learn each other through A's gossip), all see three members, then C is hard-killed and the
     * two survivors must independently converge back to a two-member view. Extends coverage beyond the
     * two-node scenarios so gossip relay + failure detection are exercised across more than one link.
     */
    #[Test]
    public function threeNodesConvergeThenSurviveOneFailing(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-swoole-trio', $runtime);

        $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');
        $addrC = new NodeAddress('swoole', 'local', 'nexus', 'node-c');

        $allSawThree = false;
        $survivorsAfterKill = 0;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, $addrA, $addrB, $addrC, &$allSawThree, &$survivorsAfterKill): void {
                $transportA = null;
                $transportB = null;
                $transportC = null;
                $nodeA = null;
                $nodeB = null;
                $nodeC = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);
                    [$transportC, $endpointC] = $this->bindTransport($runtime);

                    $fd = static fn(ClusterTopology $t): ClusterTopology => $t
                        ->withFailureDetection(minStdDev: Duration::millis(50), maxNoHeartbeat: Duration::millis(600));

                    // Full mesh: every node seeds the other two, so each holds a direct link to every
                    // peer. (A star where B and C only know each other via A's gossip is a weaker
                    // topology: a node with purely indirect knowledge of a peer cannot detect that
                    // peer's hard death — a documented limitation of gossip+phi without indirect probing.)
                    $nodeA = ClusterNode::boot(
                        $system,
                        $fd($this->fastTopology('swoole-cluster', $addrA, $endpointA, [$endpointB, $endpointC])),
                        $this->makeUserTypes(),
                        $transportA,
                    );
                    $nodeB = ClusterNode::boot(
                        $system,
                        $fd($this->fastTopology('swoole-cluster', $addrB, $endpointB, [$endpointA, $endpointC])),
                        $this->makeUserTypes(),
                        $transportB,
                    );
                    $nodeC = ClusterNode::boot(
                        $system,
                        $fd($this->fastTopology('swoole-cluster', $addrC, $endpointC, [$endpointA, $endpointB])),
                        $this->makeUserTypes(),
                        $transportC,
                    );

                    $this->pollUntil(120, static function () use ($nodeA, $nodeB, $nodeC): bool {
                        return count($nodeA->view()->members) === 3
                            && count($nodeB->view()->members) === 3
                            && count($nodeC->view()->members) === 3;
                    });
                    $allSawThree = count($nodeA->view()->members) === 3
                        && count($nodeB->view()->members) === 3
                        && count($nodeC->view()->members) === 3;

                    // Hard-kill C (no graceful Leave); A and B must Suspect→Down it and settle at two.
                    $transportC->close();
                    $nodeC = null;

                    // Assert POSITIVE convergence (each survivor sees exactly itself + the other, and not
                    // C). ClusterNode::view() can transiently return an empty view under scheduling
                    // variance — worse with three nodes' traffic on one ActorSystem — so we track each
                    // node reaching its stable shape INDEPENDENTLY (a per-call empty on one node must not
                    // reset the other), and require both to have been observed stable before concluding.
                    $aSettled = false;
                    $bSettled = false;

                    $this->pollUntil(
                        200,
                        static function () use ($nodeA, $nodeB, $addrA, $addrB, $addrC, &$aSettled, &$bSettled): bool {
                            $viewA = $nodeA->view();

                            if (count($viewA->members) === 2 && $viewA->has($addrB) && !$viewA->has($addrC)) {
                                $aSettled = true;
                            }

                            $viewB = $nodeB->view();

                            if (count($viewB->members) === 2 && $viewB->has($addrA) && !$viewB->has($addrC)) {
                                $bSettled = true;
                            }

                            return $aSettled && $bSettled;
                        },
                    );

                    $survivorsAfterKill = $aSettled && $bSettled
                        ? 2
                        : 0;
                } finally {
                    $nodeA?->shutdown();
                    $nodeB?->shutdown();
                    $nodeC?->shutdown();
                    $transportA?->close();
                    $transportB?->close();
                    $transportC?->close();
                    $system->shutdown(Duration::seconds(1));
                }
            },
        );

        $system->run();

        self::assertTrue($allSawThree, 'All three nodes must converge on a three-member view');
        self::assertSame(2, $survivorsAfterKill, 'After C is killed, A and B must each converge to a two-member view');
    }

    /**
     * SCENARIO 6 (regression): joined nodes must survive the Swoole socket recv-timeout
     * window. In a mutual-seed mesh each node's outbound link is send-only and receives
     * nothing, so it trips the coroutine socket's finite recv timeout after a few seconds.
     * A prior bug treated that timeout (errCode ETIMEDOUT) as EOF and tore the link down;
     * the resulting reconnect churn starved the failure detector into false Suspect → Down
     * within ~8 s of a clean join. This holds both nodes joined well past that window and
     * asserts neither was ever suspected and both still see two Up members.
     */
    #[Test]
    public function joinedNodesSurviveTheRecvTimeoutWindow(): void
    {
        $runtime = new SwooleRuntime();
        $events = new RecordingMembershipDispatcher();
        $system = ActorSystem::create('cluster-swoole-uptime', $runtime, eventDispatcher: $events);

        $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');

        $upA = 0;
        $upB = 0;
        $suspected = true;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, $events, $addrA, $addrB, &$upA, &$upB, &$suspected): void {
                $transportA = null;
                $transportB = null;
                $nodeA = null;
                $nodeB = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);

                    // Default cadence (1 s gossip/heartbeat, default reconnect backoff) — the
                    // conditions under which the recv-timeout bug actually cascaded to a false
                    // Suspect. Fast test overrides (100 ms gossip / 50 ms reconnect) pin phi near
                    // zero and mask the regression, so this scenario deliberately uses defaults.
                    $nodeB = ClusterNode::boot(
                        $system,
                        $this->defaultTopology('swoole-cluster', $addrB, $endpointB, [$endpointA]),
                        $this->makeUserTypes(),
                        $transportB,
                    );
                    $nodeA = ClusterNode::boot(
                        $system,
                        $this->defaultTopology('swoole-cluster', $addrA, $endpointA, [$endpointB]),
                        $this->makeUserTypes(),
                        $transportA,
                    );

                    $this->pollUntil(80, static function () use ($nodeA, $nodeB): bool {
                        return count($nodeA->view()->members) === 2
                            && count($nodeB->view()->members) === 2;
                    });

                    // Hold joined well past the ~5 s coroutine recv-timeout window and the phi
                    // give-up window that followed it. Bounded sleep (never while(true)).
                    Coroutine::sleep(9.0);

                    $upA = count($nodeA->view()->upNodes());
                    $upB = count($nodeB->view()->upNodes());
                    $suspected = $events->hasSuspected($addrA->toPathPrefix())
                        || $events->hasSuspected($addrB->toPathPrefix());
                } finally {
                    $this->cleanupCluster($system, $nodeA, $nodeB, $transportA, $transportB);
                }
            },
        );

        $system->run();

        self::assertFalse(
            $suspected,
            'Neither node may be suspected during steady uptime past the recv-timeout window',
        );
        self::assertSame(2, $upA, 'Node A must still see both members Up after the recv-timeout window');
        self::assertSame(2, $upB, 'Node B must still see both members Up after the recv-timeout window');
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
     * SCENARIO 7 (auth, positive): two nodes sharing the cluster secret authenticate each
     * other's HMAC-signed handshakes over real TCP and converge to a two-member view.
     */
    #[Test]
    public function nodesSharingTheAuthSecretConvergeOverRealTcp(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-swoole-auth-ok', $runtime);

        $upA = 0;
        $upB = 0;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, &$upA, &$upB): void {
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
                        $this->fastTopology('swoole-cluster', $addrB, $endpointB, [$endpointA])
                            ->withAuthSecret('shared-cluster-secret'),
                        $this->makeUserTypes(),
                        $transportB,
                    );
                    $nodeA = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrA, $endpointA, [$endpointB])
                            ->withAuthSecret('shared-cluster-secret'),
                        $this->makeUserTypes(),
                        $transportA,
                    );

                    $this->pollUntil(60, static function () use ($nodeA, $nodeB): bool {
                        return count($nodeA->view()->upNodes()) === 2
                            && count($nodeB->view()->upNodes()) === 2;
                    });

                    $upA = count($nodeA->view()->upNodes());
                    $upB = count($nodeB->view()->upNodes());
                } finally {
                    $this->cleanupCluster($system, $nodeA, $nodeB, $transportA, $transportB);
                }
            },
        );

        $system->run();

        self::assertSame(2, $upA, 'A must admit a peer that proves the shared secret');
        self::assertSame(2, $upB, 'B must admit a peer that proves the shared secret');
    }

    /**
     * SCENARIO 8 (auth, negative): a node presenting the WRONG secret is rejected at
     * handshake parse time — it never joins the authenticated node's view, over real TCP.
     */
    #[Test]
    public function nodeWithWrongAuthSecretIsRejected(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('cluster-swoole-auth-bad', $runtime);

        $membersA = 0;

        $runtime->scheduleOnce(
            Duration::millis(1),
            function () use ($runtime, $system, &$membersA): void {
                $transportA = null;
                $transportB = null;
                $nodeA = null;
                $nodeB = null;

                try {
                    [$transportA, $endpointA] = $this->bindTransport($runtime);
                    [$transportB, $endpointB] = $this->bindTransport($runtime);

                    $addrA = new NodeAddress('swoole', 'local', 'nexus', 'node-a');
                    $addrB = new NodeAddress('swoole', 'local', 'nexus', 'node-b');

                    // A requires the real secret and serves. B dials A signing with a
                    // different secret — every handshake B sends must be rejected.
                    $nodeA = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrA, $endpointA, [], singleNode: true)
                            ->withAuthSecret('correct-secret'),
                        $this->makeUserTypes(),
                        $transportA,
                    );
                    $nodeB = ClusterNode::boot(
                        $system,
                        $this->fastTopology('swoole-cluster', $addrB, $endpointB, [$endpointA])
                            ->withAuthSecret('attacker-secret'),
                        $this->makeUserTypes(),
                        $transportB,
                    );

                    Coroutine::sleep(1.5);

                    $membersA = count($nodeA->view()->members);
                } finally {
                    $this->cleanupCluster($system, $nodeA, $nodeB, $transportA, $transportB);
                }
            },
        );

        $system->run();

        self::assertSame(1, $membersA, 'A must reject a peer that cannot prove the shared secret');
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
     * Build a ClusterTopology with production default cadence (1 s gossip/heartbeat,
     * default reconnect backoff). Used by the recv-timeout regression scenario, which
     * needs the real steady-state timing to surface the bug.
     *
     * @param list<NodeEndpoint> $seeds
     */
    private function defaultTopology(
        string $clusterName,
        NodeAddress $self,
        NodeEndpoint $endpoint,
        array $seeds,
    ): ClusterTopology {
        return ClusterTopology::create(
            clusterName: $clusterName,
            self: $self,
            bindEndpoint: $endpoint,
            advertiseEndpoint: $endpoint,
            seeds: $seeds,
        );
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
