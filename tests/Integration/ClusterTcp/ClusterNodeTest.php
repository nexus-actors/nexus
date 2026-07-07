<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\ClusterTcp;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterNode;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackHub;
use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Pong;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\FakeObservability;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\SpyTracer;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Loopback integration tests for ClusterNode::boot.
 *
 * All nodes in a test share one LoopbackHub, one FiberRuntime, and one ActorSystem so that
 * all actor fibers are scheduled cooperatively in a single thread.
 *
 * Timer discipline: the FiberScheduler bug means that timers added to $this->timers INSIDE
 * a running callback are overwritten when advanceTimers() replaces $this->timers with the
 * $remaining snapshot it built before the callback ran. To avoid losing timers, ALL timers
 * are scheduled at absolute offsets BEFORE $system->run() is called. No nested scheduleOnce.
 *
 * view() discipline: FiberRuntime::yield() is a no-op from the main loop (timer callback
 * context) because Fiber::getCurrent() === null there. ClusterNode::queryViewAsync() lets
 * tests send GetClusterView without blocking; a pre-spawned collector actor receives the
 * reply on the next event-loop tick.
 *
 * Port allocation (avoids conflicts with other tests using 8001-8050):
 *   BASE_PORT = 9201, 9202, 9203 per node slot
 *   Each scenario shifts by an offset to prevent cross-test collisions.
 */
#[CoversClass(ClusterNode::class)]
final class ClusterNodeTest extends TestCase
{
    private const int PORT_A = 9201;
    private const int PORT_B = 9202;
    private const int PORT_C = 9203;

    private FiberRuntime $runtime;
    private ActorSystem $system;
    private LoopbackHub $hub;

    /**
     * TDD SCENARIO 1: boot() does not throw for a single-node topology;
     * self() returns the topology's own NodeAddress.
     */
    #[Test]
    public function bootDoesNotThrowForSingleNode(): void
    {
        $self = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A));

        $topology = ClusterTopology::create(
            clusterName: 'test-cluster',
            self: $self,
            bindEndpoint: $endpoint,
            advertiseEndpoint: $endpoint,
            seeds: [],
            singleNode: true,
        );

        $node = ClusterNode::boot(
            $this->system,
            $topology,
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
        );

        self::assertSame($self->toPathPrefix(), $node->self()->toPathPrefix());

        $this->runtime->scheduleOnce(Duration::millis(50), function (): void {
            $this->system->shutdown(Duration::seconds(1));
        });

        $this->system->run();
    }

    // -------------------------------------------------------------------------
    // Scenario 2: self short-circuit
    // -------------------------------------------------------------------------

    /**
     * TDD SCENARIO 2: ClusterRef targeting self-node delivers locally without hitting the wire.
     * Verified by exposing a local actor and telling it via refFor(selfAddress, path).
     *
     * All timers are at absolute offsets (no nesting) to avoid the FiberScheduler snapshot bug.
     */
    #[Test]
    public function selfRefDeliversLocallyWithoutWire(): void
    {
        $self = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A + 10));

        $topology = ClusterTopology::create(
            clusterName: 'test-cluster',
            self: $self,
            bindEndpoint: $endpoint,
            advertiseEndpoint: $endpoint,
            seeds: [],
            singleNode: true,
        );

        $userTypes = new TypeRegistry();
        $userTypes->registerFromAttribute(Ping::class);

        $node = ClusterNode::boot(
            $this->system,
            $topology,
            $userTypes,
            new LoopbackMeshTransport($this->hub, $this->runtime),
        );

        /** @var list<Ping> $received */
        $received = [];

        $behavior = Behavior::receive(
            static function ($ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof Ping) {
                    $received[] = $msg;
                }

                return Behavior::same();
            },
        );

        $ref = $this->system->spawn(Props::fromBehavior($behavior), 'ping-actor');
        $node->expose($ref);

        // T=50ms: tell via self-routed ClusterRef.
        $this->runtime->scheduleOnce(
            Duration::millis(50),
            static function () use ($node, $self, $ref): void {
                $clusterRef = $node->refFor($self, $ref->path());
                $clusterRef->tell(new Ping('local-delivery'));
            },
        );

        // T=300ms: shutdown — well after the delivery has been processed.
        $this->runtime->scheduleOnce(Duration::millis(300), function (): void {
            $this->system->shutdown(Duration::seconds(1));
        });

        $this->system->run();

        self::assertCount(1, $received, 'ClusterRef targeting self should deliver locally');
        self::assertSame('local-delivery', $received[0]->text);
    }

    // -------------------------------------------------------------------------
    // Scenario 3: two-node tell A→B
    // -------------------------------------------------------------------------

    /**
     * TDD SCENARIO 3: tell() from node A to an actor exposed on node B delivers the message
     * after handshake/gossip propagates over the loopback transport.
     */
    #[Test]
    public function tellFromAToBDeliversMessage(): void
    {
        $addrA = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('test', 'local', 'nexus', 'node-b');
        $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A + 20));
        $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_B + 20));

        // Boot B first so it's already serving when A dials it.
        $topologyB = $this->fastTopology('test-cluster', $addrB, $endpointB, [$endpointA]);
        $topologyA = $this->fastTopology('test-cluster', $addrA, $endpointA, [$endpointB]);

        // Each node gets its own TypeRegistry (boot() mutates the registry in-place).
        $nodeB = ClusterNode::boot(
            $this->system,
            $topologyB,
            $this->makeUserTypes(),
            new LoopbackMeshTransport($this->hub, $this->runtime),
        );
        $nodeA = ClusterNode::boot(
            $this->system,
            $topologyA,
            $this->makeUserTypes(),
            new LoopbackMeshTransport($this->hub, $this->runtime),
        );

        /** @var list<Ping> $received */
        $received = [];

        $behavior = Behavior::receive(
            static function ($ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof Ping) {
                    $received[] = $msg;
                }

                return Behavior::same();
            },
        );

        $ref = $this->system->spawn(Props::fromBehavior($behavior), 'ping-receiver');
        $nodeB->expose($ref);

        // T=600ms: handshake + first gossip round-trip complete — send the Ping.
        $this->runtime->scheduleOnce(
            Duration::millis(600),
            static function () use ($nodeA, $addrB, $ref): void {
                $clusterRef = $nodeA->refFor($addrB, $ref->path());
                $clusterRef->tell(new Ping('hello-from-a'));
            },
        );

        // T=1000ms: shutdown.
        $this->runtime->scheduleOnce(Duration::millis(1000), function (): void {
            $this->system->shutdown(Duration::seconds(1));
        });

        $this->system->run();

        self::assertCount(1, $received, 'A→B tell should deliver exactly one Ping');
        self::assertSame('hello-from-a', $received[0]->text);
    }

    // -------------------------------------------------------------------------
    // Scenario 4: three-node gossip view convergence
    // -------------------------------------------------------------------------

    /**
     * TDD SCENARIO 4: After B and C join via A as seed, all three nodes' ClusterViews
     * contain all three members after gossip converges.
     *
     * Views are queried via queryViewAsync() + a pre-spawned collector actor so that the
     * query can be issued from a timer callback (where FiberRuntime::yield() is a no-op).
     */
    #[Test]
    public function threeNodeViewConverges(): void
    {
        $addrA = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('test', 'local', 'nexus', 'node-b');
        $addrC = new NodeAddress('test', 'local', 'nexus', 'node-c');
        $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A + 30));
        $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_B + 30));
        $endpointC = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_C + 30));

        // A is the seed for B and C. Boot A first (must serve before B/C dial it).
        $nodeA = ClusterNode::boot(
            $this->system,
            $this->fastTopology('test-cluster', $addrA, $endpointA, [], singleNode: true),
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
        );
        $nodeB = ClusterNode::boot(
            $this->system,
            $this->fastTopology('test-cluster', $addrB, $endpointB, [$endpointA]),
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
        );
        $nodeC = ClusterNode::boot(
            $this->system,
            $this->fastTopology('test-cluster', $addrC, $endpointC, [$endpointA]),
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
        );

        /** @var list<ClusterView> $capturedViews */
        $capturedViews = [];

        // Collector actor: accumulates up to 3 ClusterView replies (one per node query).
        $collectorRef = $this->system->spawnAnonymous(
            Props::fromBehavior(
                Behavior::receive(
                    static function ($ctx, object $msg) use (&$capturedViews): Behavior {
                        if ($msg instanceof ClusterView) {
                            $capturedViews[] = $msg;
                        }

                        return Behavior::same();
                    },
                ),
            ),
        );

        // T=1500ms: 15+ gossip cycles at 100ms — send async view queries to all three nodes.
        $this->runtime->scheduleOnce(
            Duration::millis(1500),
            static function () use ($nodeA, $nodeB, $nodeC, $collectorRef): void {
                $nodeA->queryViewAsync($collectorRef);
                $nodeB->queryViewAsync($collectorRef);
                $nodeC->queryViewAsync($collectorRef);
            },
        );

        // T=2000ms: shutdown after views have been delivered to the collector.
        $this->runtime->scheduleOnce(Duration::millis(2000), function (): void {
            $this->system->shutdown(Duration::seconds(1));
        });

        $this->system->run();

        self::assertCount(3, $capturedViews, 'All three view queries should have been answered');
        self::assertCount(3, $capturedViews[0]->members, 'Node A should see 3 members after convergence');
        self::assertCount(3, $capturedViews[1]->members, 'Node B should see 3 members after convergence');
        self::assertCount(3, $capturedViews[2]->members, 'Node C should see 3 members after convergence');
    }

    // -------------------------------------------------------------------------
    // Scenario 5: graceful leave
    // -------------------------------------------------------------------------

    /**
     * TDD SCENARIO 5: When C calls shutdown(), A and B's views drop C after the
     * LeaveReceived message is processed by the membership actor.
     */
    #[Test]
    public function gracefulLeaveDropsNodeFromPeerViews(): void
    {
        $addrA = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('test', 'local', 'nexus', 'node-b');
        $addrC = new NodeAddress('test', 'local', 'nexus', 'node-c');
        $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A + 40));
        $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_B + 40));
        $endpointC = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_C + 40));

        $nodeA = ClusterNode::boot(
            $this->system,
            $this->fastTopology('test-cluster', $addrA, $endpointA, [], singleNode: true),
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
        );
        $nodeB = ClusterNode::boot(
            $this->system,
            $this->fastTopology('test-cluster', $addrB, $endpointB, [$endpointA]),
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
        );
        $nodeC = ClusterNode::boot(
            $this->system,
            $this->fastTopology('test-cluster', $addrC, $endpointC, [$endpointA]),
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
        );

        /** @var list<ClusterView> $capturedViews */
        $capturedViews = [];

        // Collector actor: accumulates ClusterView replies from A and B.
        $collectorRef = $this->system->spawnAnonymous(
            Props::fromBehavior(
                Behavior::receive(
                    static function ($ctx, object $msg) use (&$capturedViews): Behavior {
                        if ($msg instanceof ClusterView) {
                            $capturedViews[] = $msg;
                        }

                        return Behavior::same();
                    },
                ),
            ),
        );

        // T=1000ms: cluster converged — C broadcasts Leave.
        $this->runtime->scheduleOnce(
            Duration::millis(1000),
            static function () use ($nodeC): void {
                $nodeC->shutdown();
            },
        );

        // T=2000ms: query A and B's views — C should be absent.
        $this->runtime->scheduleOnce(
            Duration::millis(2000),
            static function () use ($nodeA, $nodeB, $collectorRef): void {
                $nodeA->queryViewAsync($collectorRef);
                $nodeB->queryViewAsync($collectorRef);
            },
        );

        // T=2500ms: shutdown.
        $this->runtime->scheduleOnce(Duration::millis(2500), function (): void {
            $this->system->shutdown(Duration::seconds(1));
        });

        $this->system->run();

        self::assertCount(2, $capturedViews, 'Both A and B view queries should have been answered');
        self::assertSame(2, count($capturedViews[0]->members), 'A should see exactly 2 members after C leaves');
        self::assertSame(2, count($capturedViews[1]->members), 'B should see exactly 2 members after C leaves');
    }

    // -------------------------------------------------------------------------
    // Scenario 6: cross-cluster ask round-trip
    // -------------------------------------------------------------------------

    /**
     * TDD SCENARIO 6: ask() from node A to an actor on node B receives the reply after
     * the handshake is complete and the actor replies via $ctx->sender()->tell().
     *
     * The ask is issued inside an actor behavior (which runs in a Fiber) so Future::await()
     * can suspend and resume cooperatively. The timeout is 500ms — well above the loopback RTT.
     */
    #[Test]
    public function askFromAToBRoundTrip(): void
    {
        $addrA = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('test', 'local', 'nexus', 'node-b');
        $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A + 50));
        $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_B + 50));

        $topologyB = $this->fastTopology('test-cluster', $addrB, $endpointB, [$endpointA]);
        $topologyA = $this->fastTopology('test-cluster', $addrA, $endpointA, [$endpointB]);

        // Boot B first so it is already serving when A dials it.
        $nodeB = ClusterNode::boot(
            $this->system,
            $topologyB,
            $this->makeUserTypes(),
            new LoopbackMeshTransport($this->hub, $this->runtime),
        );
        $nodeA = ClusterNode::boot(
            $this->system,
            $topologyA,
            $this->makeUserTypes(),
            new LoopbackMeshTransport($this->hub, $this->runtime),
        );

        /** @var Pong|null $captured */
        $captured = null;

        // Pong actor on B: replies to inbound asks via $ctx->sender() (a ClusterReplyRef on the ask path).
        $pongBehavior = Behavior::receive(
            static function ($ctx, object $msg): Behavior {
                if ($msg instanceof Ping) {
                    $ctx->sender()?->tell(new Pong($msg->text));
                }

                return Behavior::same();
            },
        );
        $pongRef = $this->system->spawn(Props::fromBehavior($pongBehavior), 'pong-actor-ask');
        $nodeB->expose($pongRef);

        // Asker actor on A: triggered by a Ping, issues the cross-cluster ask and captures the Pong.
        // Running inside a Fiber, Future::await() suspends cooperatively until the reply frame arrives.
        $pongPath = $pongRef->path();
        $askerBehavior = Behavior::receive(
            static function ($ctx, object $msg) use (&$captured, $nodeA, $addrB, $pongPath): Behavior {
                if ($msg instanceof Ping) {
                    $clusterRef = $nodeA->refFor($addrB, $pongPath);
                    $reply = $clusterRef->ask(new Ping('ask-hello'), Duration::millis(500))->await();

                    if ($reply instanceof Pong) {
                        $captured = $reply;
                    }
                }

                return Behavior::same();
            },
        );
        $askerRef = $this->system->spawn(Props::fromBehavior($askerBehavior), 'asker-actor');

        // T=600ms: handshake + first gossip round-trip complete — trigger the cross-cluster ask.
        $this->runtime->scheduleOnce(
            Duration::millis(600),
            static function () use ($askerRef): void {
                $askerRef->tell(new Ping('go'));
            },
        );

        // T=1500ms: shutdown — the ask (500ms budget) completes well before this.
        $this->runtime->scheduleOnce(Duration::millis(1500), function (): void {
            $this->system->shutdown(Duration::seconds(1));
        });

        $this->system->run();

        self::assertNotNull($captured, 'Ask should have received a Pong reply from node B');
        self::assertSame('ask-hello', $captured->text);
    }

    // -------------------------------------------------------------------------
    // Scenario 7: ask timeout
    // -------------------------------------------------------------------------

    /**
     * TDD SCENARIO 7: ask() to a path with no registered actor on the remote node times out
     * with AskTimeoutException after the specified budget elapses.
     *
     * The message is delivered to B but dropped (no actor at that path in B's local registry).
     * The ask registry timeout fires, fails the Future, and the awaiting fiber receives
     * AskTimeoutException.
     */
    #[Test]
    public function askTimesOutWhenNoActorAtTargetPath(): void
    {
        $addrA = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('test', 'local', 'nexus', 'node-b');
        $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A + 60));
        $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_B + 60));

        $topologyB = $this->fastTopology('test-cluster', $addrB, $endpointB, [$endpointA]);
        $topologyA = $this->fastTopology('test-cluster', $addrA, $endpointA, [$endpointB]);

        ClusterNode::boot(
            $this->system,
            $topologyB,
            $this->makeUserTypes(),
            new LoopbackMeshTransport($this->hub, $this->runtime),
        );
        $nodeA = ClusterNode::boot(
            $this->system,
            $topologyA,
            $this->makeUserTypes(),
            new LoopbackMeshTransport($this->hub, $this->runtime),
        );

        /** @var bool $timedOut */
        $timedOut = false;

        // Dead path: nothing is exposed at this path on node B.
        $deadPath = ActorPath::fromString('/nexus/user/nonexistent-actor');

        // Asker actor: asks a non-existent path on B and captures the timeout.
        $askerBehavior = Behavior::receive(
            static function ($ctx, object $msg) use (&$timedOut, $nodeA, $addrB, $deadPath): Behavior {
                if ($msg instanceof Ping) {
                    $clusterRef = $nodeA->refFor($addrB, $deadPath);

                    try {
                        $clusterRef->ask(new Ping('will-timeout'), Duration::millis(150))->await();
                    } catch (AskTimeoutException) {
                        $timedOut = true;
                    }
                }

                return Behavior::same();
            },
        );
        $askerRef = $this->system->spawn(Props::fromBehavior($askerBehavior), 'timeout-asker');

        // T=600ms: handshake complete — trigger the ask.
        $this->runtime->scheduleOnce(
            Duration::millis(600),
            static function () use ($askerRef): void {
                $askerRef->tell(new Ping('go'));
            },
        );

        // T=1500ms: shutdown — well after the 150ms timeout fires at T≈750ms.
        $this->runtime->scheduleOnce(Duration::millis(1500), function (): void {
            $this->system->shutdown(Duration::seconds(1));
        });

        $this->system->run();

        self::assertTrue($timedOut, 'Ask to non-existent path should have timed out with AskTimeoutException');
    }

    // -------------------------------------------------------------------------
    // Scenario: cluster.handshake span
    // -------------------------------------------------------------------------

    /**
     * TDD SCENARIO: When two nodes connect, cluster.handshake spans are recorded
     * with SpanKind::Internal and an `accepted` outcome attribute.
     */
    #[Test]
    public function handshakeSpanIsRecordedWithInternalKindAndAcceptedOutcome(): void
    {
        $spy = new SpyTracer();
        $obs = new FakeObservability($spy);

        $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A + 50));
        $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_B + 50));
        $addrA = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('test', 'local', 'nexus', 'node-b');

        $topologyA = $this->fastTopology('test', $addrA, $endpointA, [$endpointB]);
        $topologyB = $this->fastTopology('test', $addrB, $endpointB, [$endpointA]);

        ClusterNode::boot(
            $this->system,
            $topologyA,
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
            observability: $obs,
        );

        ClusterNode::boot(
            $this->system,
            $topologyB,
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
            observability: $obs,
        );

        $this->runtime->scheduleOnce(Duration::millis(300), function (): void {
            $this->system->shutdown(Duration::seconds(1));
        });

        $this->system->run();

        $handshakeSpans = $spy->spansNamed('cluster.handshake');
        self::assertNotEmpty($handshakeSpans, 'At least one cluster.handshake span should have been recorded');

        foreach ($handshakeSpans as $entry) {
            self::assertSame(SpanKind::Internal, $entry['kind']);
            self::assertArrayHasKey('nexus.cluster.handshake.outcome', $entry['span']->attributes);
        }

        // At least one span should have outcome=accepted (valid Handshake frames were exchanged)
        $accepted = array_filter(
            $handshakeSpans,
            static fn(array $e): bool => $e['span']->attributes['nexus.cluster.handshake.outcome'] === 'accepted',
        );
        self::assertNotEmpty($accepted, 'At least one handshake should have been accepted');
    }

    /**
     * Sustained-uptime smoke test: two joined nodes must remain Up over many gossip
     * cycles with an aggressive failure-detection window — a gross false-Suspect /
     * false-Down regression would fail this.
     *
     * Runs ~25 gossip cycles (100 ms) with a 500 ms give-up window and asserts BOTH
     * nodes still see BOTH members Up. Note: this loopback scenario does NOT
     * reproduce the specific gossip-starvation failure fixed in f0322d38 — that mode
     * needs the real-socket boot-frame churn that seeds the phi window before it
     * starves (a clean loopback boot leaves the detector with too few samples, so
     * phi stays 0). f0322d38 is validated by the two-node Swoole example
     * (examples/nexus-cluster-tcp) plus code analysis; this test guards the broader
     * "nodes stay Up while gossiping" invariant.
     */
    #[Test]
    public function joinedNodesStayUpThroughSustainedGossip(): void
    {
        $addrA = new NodeAddress('test', 'local', 'nexus', 'node-a');
        $addrB = new NodeAddress('test', 'local', 'nexus', 'node-b');
        $endpointA = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_A + 60));
        $endpointB = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(self::PORT_B + 60));

        $topoA = $this->fastTopology('test-cluster', $addrA, $endpointA, [], singleNode: true)
            ->withFailureDetection(minStdDev: Duration::millis(50), maxNoHeartbeat: Duration::millis(500));
        $topoB = $this->fastTopology('test-cluster', $addrB, $endpointB, [$endpointA])
            ->withFailureDetection(minStdDev: Duration::millis(50), maxNoHeartbeat: Duration::millis(500));

        $nodeA = ClusterNode::boot(
            $this->system,
            $topoA,
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
        );
        $nodeB = ClusterNode::boot(
            $this->system,
            $topoB,
            transport: new LoopbackMeshTransport($this->hub, $this->runtime),
        );

        /** @var list<ClusterView> $capturedViews */
        $capturedViews = [];

        $collectorRef = $this->system->spawnAnonymous(
            Props::fromBehavior(
                Behavior::receive(
                    static function ($ctx, object $msg) use (&$capturedViews): Behavior {
                        if ($msg instanceof ClusterView) {
                            $capturedViews[] = $msg;
                        }

                        return Behavior::same();
                    },
                ),
            ),
        );

        // T=2500ms: 25 gossip cycles, five give-up windows past a starving detector.
        $this->runtime->scheduleOnce(
            Duration::millis(2500),
            static function () use ($nodeA, $nodeB, $collectorRef): void {
                $nodeA->queryViewAsync($collectorRef);
                $nodeB->queryViewAsync($collectorRef);
            },
        );

        $this->runtime->scheduleOnce(Duration::millis(3000), function (): void {
            $this->system->shutdown(Duration::seconds(1));
        });

        $this->system->run();

        self::assertCount(2, $capturedViews, 'Both view queries should have been answered');
        self::assertCount(
            2,
            $capturedViews[0]->upNodes(),
            'Node A must still see both members Up (not starved into Suspect/Down)',
        );
        self::assertCount(
            2,
            $capturedViews[1]->upNodes(),
            'Node B must still see both members Up (not starved into Suspect/Down)',
        );
    }

    protected function setUp(): void
    {
        $this->runtime = new FiberRuntime();
        $this->system = ActorSystem::create('cluster-test', $this->runtime);
        $this->hub = new LoopbackHub();
    }

    // -------------------------------------------------------------------------
    // Scenario 1: single-node boot
    // -------------------------------------------------------------------------


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a TypeRegistry pre-populated with the Ping fixture type.
     * Each call returns a fresh instance (boot() mutates by adding cluster wire types in-place).
     */
    private function makeUserTypes(): TypeRegistry
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Ping::class);
        $registry->registerFromAttribute(Pong::class);

        return $registry;
    }

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
}
