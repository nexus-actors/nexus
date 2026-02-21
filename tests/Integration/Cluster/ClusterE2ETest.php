<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Cluster;

use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use Monadial\Nexus\Cluster\Directory\InMemoryDirectory;
use Monadial\Nexus\Cluster\RemoteActorRef;
use Monadial\Nexus\Cluster\Router\MessageRouter;
use Monadial\Nexus\Cluster\Router\SerializingRouter;
use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Cluster\Serialization\PhpNativeClusterSerializer;
use Monadial\Nexus\Cluster\Swoole\Transport\UnixSocketTransport;
use Monadial\Nexus\Cluster\Transport\Transport;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

/**
 * End-to-end integration test for the full cluster IPC stack.
 *
 * Simulates a multi-worker cluster within a single Swoole coroutine context
 * (no forked processes). Tests the full path:
 *   ClusterNode -> ConsistentHashRing -> RemoteActorRef -> UnixSocketTransport
 *   -> ClusterSerializer -> message delivery to local actors.
 *
 * Each "worker" has its own ActorSystem + SwooleRuntime + UnixSocketTransport,
 * communicating over real AF_UNIX domain sockets.
 */
#[CoversClass(ClusterNode::class)]
#[RequiresPhpExtension('swoole')]
final class ClusterE2ETest extends TestCase
{
    private string $socketDir;

    #[Test]
    public function crossWorkerMessageDelivery(): void
    {
        $received = [];

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use (&$received): void {
            $workerCount = 2;
            $ring = new ConsistentHashRing($workerCount);
            $serializer = new PhpNativeClusterSerializer();
            $directory = new InMemoryDirectory();

            // Create transports with real Unix sockets
            $transport0 = new UnixSocketTransport(0, $workerCount, $this->socketDir);
            $transport1 = new UnixSocketTransport(1, $workerCount, $this->socketDir);

            // Create runtimes and mark them as inside Co\run() so spawn() creates
            // coroutines immediately instead of queueing them as pending.
            $runtime0 = $this->createActiveRuntime();
            $runtime1 = $this->createActiveRuntime();

            $system0 = ActorSystem::create('worker-0', $runtime0);
            $system1 = ActorSystem::create('worker-1', $runtime1);

            // Create routers and cluster nodes
            $router0 = new SerializingRouter($transport0, $serializer);
            $router1 = new SerializingRouter($transport1, $serializer);
            $node0 = $this->createClusterNode(0, $system0, $router0, $ring, $directory, $transport0, $serializer);
            $node1 = $this->createClusterNode(1, $system1, $router1, $ring, $directory, $transport1, $serializer);

            // Bind server sockets and allow them to start accepting
            $transport0->bind();
            $transport1->bind();
            Coroutine::sleep(0.05);

            // Connect peers (each worker connects to the other's server socket)
            $transport0->connectToPeers();
            $transport1->connectToPeers();

            // Start listening for incoming transport messages
            $node0->start();
            $node1->start();

            // Find actor names that deterministically hash to each worker
            $nameForWorker0 = $this->findNameForWorker($ring, 0);
            $nameForWorker1 = $this->findNameForWorker($ring, 1);

            // Create a sink behavior that captures message payloads
            $sinkBehavior = Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                    if (isset($msg->payload)) {
                        $received[] = $msg->payload;
                    }

                    return Behavior::same();
                },
            );

            // Spawn actors — each on the node that owns it (local spawn)
            $ref0on0 = $node0->spawn(Props::fromBehavior($sinkBehavior), $nameForWorker0);
            $ref1on1 = $node1->spawn(Props::fromBehavior($sinkBehavior), $nameForWorker1);

            // Verify local refs are LocalActorRef
            self::assertInstanceOf(LocalActorRef::class, $ref0on0);
            self::assertInstanceOf(LocalActorRef::class, $ref1on1);

            // Get remote refs: node 0 looking up actor on worker 1, and vice versa
            $ref1on0 = $node0->actorFor("/user/{$nameForWorker1}");
            $ref0on1 = $node1->actorFor("/user/{$nameForWorker0}");

            self::assertInstanceOf(RemoteActorRef::class, $ref1on0);
            self::assertInstanceOf(RemoteActorRef::class, $ref0on1);

            // Send cross-worker messages via RemoteActorRef -> transport -> serialization
            // From node 0 -> actor on worker 1
            $ref1on0->tell((object) ['payload' => 'from-worker-0']);
            // From node 1 -> actor on worker 0
            $ref0on1->tell((object) ['payload' => 'from-worker-1']);

            // Allow coroutines time to deliver and process messages
            Coroutine::sleep(0.3);

            // Cleanup
            $transport0->close();
            $transport1->close();
            $system0->shutdown(Duration::millis(100));
            $system1->shutdown(Duration::millis(100));
        });

        // Verify cross-worker delivery happened
        self::assertContains(
            'from-worker-0',
            $received,
            'Message from worker 0 should be delivered to actor on worker 1',
        );
        self::assertContains(
            'from-worker-1',
            $received,
            'Message from worker 1 should be delivered to actor on worker 0',
        );
        self::assertCount(2, $received);
    }

    #[Test]
    public function hashRingDeterminesActorPlacement(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        run(function (): void {
            $workerCount = 4;
            $ring = new ConsistentHashRing($workerCount);
            $serializer = new PhpNativeClusterSerializer();
            $directory = new InMemoryDirectory();

            $transports = [];
            $runtimes = [];
            $systems = [];
            $nodes = [];

            for ($i = 0; $i < $workerCount; $i++) {
                $transports[$i] = new UnixSocketTransport($i, $workerCount, $this->socketDir);
                $runtimes[$i] = $this->createActiveRuntime();
                $systems[$i] = ActorSystem::create("worker-{$i}", $runtimes[$i]);
                $router = new SerializingRouter($transports[$i], $serializer);
                $nodes[$i] = $this->createClusterNode(
                    $i,
                    $systems[$i],
                    $router,
                    $ring,
                    $directory,
                    $transports[$i],
                    $serializer,
                );
            }

            // Bind all server sockets
            for ($i = 0; $i < $workerCount; $i++) {
                $transports[$i]->bind();
            }

            Coroutine::sleep(0.05);

            // Connect all peers
            for ($i = 0; $i < $workerCount; $i++) {
                $transports[$i]->connectToPeers();
            }

            // Spawn 20 actors from node 0 — hash ring determines local vs remote
            $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());
            $localCount = 0;
            $remoteCount = 0;

            for ($i = 0; $i < 20; $i++) {
                $ref = $nodes[0]->spawn(Props::fromBehavior($behavior), "test-actor-{$i}");

                if ($ref instanceof LocalActorRef) {
                    $localCount++;
                } else {
                    $remoteCount++;
                }
            }

            // With 4 workers and 20 actors, node 0 should own roughly 5 (25%)
            // but consistent hashing distribution varies. Assert at least some of each.
            self::assertGreaterThan(0, $localCount, 'Node 0 should own at least 1 actor');
            self::assertGreaterThan(0, $remoteCount, 'Some actors should be remote');
            self::assertSame(20, $localCount + $remoteCount);

            // Cleanup
            for ($i = 0; $i < $workerCount; $i++) {
                $transports[$i]->close();
                $systems[$i]->shutdown(Duration::millis(100));
            }
        });
    }

    #[Test]
    public function multipleMessagesDeliveredInOrderAcrossWorkers(): void
    {
        $received = [];

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use (&$received): void {
            $workerCount = 2;
            $ring = new ConsistentHashRing($workerCount);
            $serializer = new PhpNativeClusterSerializer();
            $directory = new InMemoryDirectory();

            $transport0 = new UnixSocketTransport(0, $workerCount, $this->socketDir);
            $transport1 = new UnixSocketTransport(1, $workerCount, $this->socketDir);

            $runtime0 = $this->createActiveRuntime();
            $runtime1 = $this->createActiveRuntime();
            $system0 = ActorSystem::create('worker-0', $runtime0);
            $system1 = ActorSystem::create('worker-1', $runtime1);

            $router0 = new SerializingRouter($transport0, $serializer);
            $router1 = new SerializingRouter($transport1, $serializer);
            $node0 = $this->createClusterNode(0, $system0, $router0, $ring, $directory, $transport0, $serializer);
            $node1 = $this->createClusterNode(1, $system1, $router1, $ring, $directory, $transport1, $serializer);

            $transport0->bind();
            $transport1->bind();
            Coroutine::sleep(0.05);
            $transport0->connectToPeers();
            $transport1->connectToPeers();
            $node0->start();
            $node1->start();

            // Find a name that hashes to worker 1
            $nameForWorker1 = $this->findNameForWorker($ring, 1);

            // Spawn sink actor on node 1 (local)
            $sinkBehavior = Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                    if (isset($msg->seq)) {
                        $received[] = $msg->seq;
                    }

                    return Behavior::same();
                },
            );
            $node1->spawn(Props::fromBehavior($sinkBehavior), $nameForWorker1);

            // Get remote ref from node 0
            $remoteRef = $node0->actorFor("/user/{$nameForWorker1}");
            self::assertNotNull($remoteRef);
            self::assertInstanceOf(RemoteActorRef::class, $remoteRef);

            // Send 100 messages from node 0 -> actor on node 1 via transport
            for ($i = 0; $i < 100; $i++) {
                $remoteRef->tell((object) ['seq' => $i]);
            }

            // Wait for delivery (Unix sockets preserve ordering)
            Coroutine::sleep(0.5);

            $transport0->close();
            $transport1->close();
            $system0->shutdown(Duration::millis(100));
            $system1->shutdown(Duration::millis(100));
        });

        self::assertCount(100, $received, 'All 100 messages should be delivered');

        // Verify ordering is preserved (Unix domain sockets are ordered)
        for ($i = 0; $i < 100; $i++) {
            self::assertSame($i, $received[$i], "Message {$i} should be in order");
        }
    }

    #[Test]
    public function directoryRegistrationDuringSpawn(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        run(function (): void {
            $workerCount = 2;
            $ring = new ConsistentHashRing($workerCount);
            $serializer = new PhpNativeClusterSerializer();
            $directory = new InMemoryDirectory();

            $transport0 = new UnixSocketTransport(0, $workerCount, $this->socketDir);
            $transport1 = new UnixSocketTransport(1, $workerCount, $this->socketDir);

            $runtime0 = $this->createActiveRuntime();
            $runtime1 = $this->createActiveRuntime();
            $system0 = ActorSystem::create('worker-0', $runtime0);
            $system1 = ActorSystem::create('worker-1', $runtime1);

            $router0 = new SerializingRouter($transport0, $serializer);
            $router1 = new SerializingRouter($transport1, $serializer);
            $node0 = $this->createClusterNode(0, $system0, $router0, $ring, $directory, $transport0, $serializer);
            $node1 = $this->createClusterNode(1, $system1, $router1, $ring, $directory, $transport1, $serializer);

            $transport0->bind();
            $transport1->bind();
            Coroutine::sleep(0.05);
            $transport0->connectToPeers();
            $transport1->connectToPeers();

            $nameForWorker0 = $this->findNameForWorker($ring, 0);
            $nameForWorker1 = $this->findNameForWorker($ring, 1);

            $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());

            // Spawn local actor on node 0
            $node0->spawn(Props::fromBehavior($behavior), $nameForWorker0);
            // Spawn remote actor from node 0 (registers in directory for worker 1)
            $node0->spawn(Props::fromBehavior($behavior), $nameForWorker1);

            // Directory should have both actors registered
            self::assertTrue($directory->has("/user/{$nameForWorker0}"));
            self::assertTrue($directory->has("/user/{$nameForWorker1}"));

            // Verify correct worker assignment
            self::assertSame(0, $directory->lookup("/user/{$nameForWorker0}"));
            self::assertSame(1, $directory->lookup("/user/{$nameForWorker1}"));

            // actorFor should resolve correctly
            $localRef = $node0->actorFor("/user/{$nameForWorker0}");
            $remoteRef = $node0->actorFor("/user/{$nameForWorker1}");
            self::assertInstanceOf(LocalActorRef::class, $localRef);
            self::assertInstanceOf(RemoteActorRef::class, $remoteRef);

            // Unknown actor should return null
            self::assertNull($node0->actorFor('/user/nonexistent'));

            $transport0->close();
            $transport1->close();
            $system0->shutdown(Duration::millis(100));
            $system1->shutdown(Duration::millis(100));
        });
    }

    #[Test]
    public function bidirectionalCrossWorkerMessaging(): void
    {
        $receivedOnWorker0 = [];
        $receivedOnWorker1 = [];

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use (&$receivedOnWorker0, &$receivedOnWorker1): void {
            $workerCount = 2;
            $ring = new ConsistentHashRing($workerCount);
            $serializer = new PhpNativeClusterSerializer();
            $directory = new InMemoryDirectory();

            $transport0 = new UnixSocketTransport(0, $workerCount, $this->socketDir);
            $transport1 = new UnixSocketTransport(1, $workerCount, $this->socketDir);

            $runtime0 = $this->createActiveRuntime();
            $runtime1 = $this->createActiveRuntime();
            $system0 = ActorSystem::create('worker-0', $runtime0);
            $system1 = ActorSystem::create('worker-1', $runtime1);

            $router0 = new SerializingRouter($transport0, $serializer);
            $router1 = new SerializingRouter($transport1, $serializer);
            $node0 = $this->createClusterNode(0, $system0, $router0, $ring, $directory, $transport0, $serializer);
            $node1 = $this->createClusterNode(1, $system1, $router1, $ring, $directory, $transport1, $serializer);

            $transport0->bind();
            $transport1->bind();
            Coroutine::sleep(0.05);
            $transport0->connectToPeers();
            $transport1->connectToPeers();
            $node0->start();
            $node1->start();

            $nameForWorker0 = $this->findNameForWorker($ring, 0);
            $nameForWorker1 = $this->findNameForWorker($ring, 1);

            // Spawn sink on worker 0
            $sink0 = Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$receivedOnWorker0): Behavior {
                    if (isset($msg->payload)) {
                        $receivedOnWorker0[] = $msg->payload;
                    }

                    return Behavior::same();
                },
            );
            $node0->spawn(Props::fromBehavior($sink0), $nameForWorker0);

            // Spawn sink on worker 1
            $sink1 = Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$receivedOnWorker1): Behavior {
                    if (isset($msg->payload)) {
                        $receivedOnWorker1[] = $msg->payload;
                    }

                    return Behavior::same();
                },
            );
            $node1->spawn(Props::fromBehavior($sink1), $nameForWorker1);

            // Cross-worker: node 1 sends to actor on worker 0
            $remoteToWorker0 = $node1->actorFor("/user/{$nameForWorker0}");
            self::assertInstanceOf(RemoteActorRef::class, $remoteToWorker0);
            $remoteToWorker0->tell((object) ['payload' => 'hello-from-1']);

            // Cross-worker: node 0 sends to actor on worker 1
            $remoteToWorker1 = $node0->actorFor("/user/{$nameForWorker1}");
            self::assertInstanceOf(RemoteActorRef::class, $remoteToWorker1);
            $remoteToWorker1->tell((object) ['payload' => 'hello-from-0']);

            Coroutine::sleep(0.3);

            $transport0->close();
            $transport1->close();
            $system0->shutdown(Duration::millis(100));
            $system1->shutdown(Duration::millis(100));
        });

        self::assertCount(1, $receivedOnWorker0);
        self::assertSame('hello-from-1', $receivedOnWorker0[0]);
        self::assertCount(1, $receivedOnWorker1);
        self::assertSame('hello-from-0', $receivedOnWorker1[0]);
    }

    protected function setUp(): void
    {
        $this->socketDir = sys_get_temp_dir() . '/nexus-e2e-' . getmypid();

        if (!is_dir($this->socketDir)) {
            mkdir($this->socketDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $files = glob($this->socketDir . '/*.sock');

        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        @rmdir($this->socketDir);
    }

    /**
     * Create a SwooleRuntime and mark it as "inside Co\run()" so that spawn()
     * creates coroutines immediately rather than queueing them as pending.
     *
     * This is necessary because we are already inside a Swoole\Coroutine\run()
     * block. Calling SwooleRuntime::run() would start a nested Co\run(), which
     * conflicts. Instead, we set the internal flag via reflection so the runtime
     * cooperates with the existing coroutine scheduler.
     */
    private function createActiveRuntime(): SwooleRuntime
    {
        $runtime = new SwooleRuntime();

        $prop = new ReflectionProperty(SwooleRuntime::class, 'insideCoRun');
        $prop->setValue($runtime, true);

        return $runtime;
    }

    private function createClusterNode(
        int $workerId,
        ActorSystem $system,
        MessageRouter $router,
        ConsistentHashRing $ring,
        ActorDirectory $directory,
        Transport $transport,
        ClusterSerializer $serializer,
    ): ClusterNode {
        /** @var callable(ActorPath, int): ActorRef<object> $remoteRefFactory */
        $remoteRefFactory = static fn(ActorPath $path, int $targetWorker): ActorRef => new RemoteActorRef(
            $path,
            $targetWorker,
            $transport,
            $serializer,
            $directory,
        );

        return new ClusterNode($workerId, $system, $router, $ring, $directory, $remoteRefFactory);
    }

    /**
     * Find an actor name that the hash ring assigns to the given worker ID.
     *
     * Iterates candidate names until one hashes to the target worker.
     */
    private function findNameForWorker(ConsistentHashRing $ring, int $workerId): string
    {
        for ($i = 0; $i < 10000; $i++) {
            $name = "actor-{$i}";

            if ($ring->getWorker($name) === $workerId) {
                return $name;
            }
        }

        self::fail("Could not find a name that hashes to worker {$workerId}");
    }
}
