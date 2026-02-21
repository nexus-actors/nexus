<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\ThreadCluster;

use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use Monadial\Nexus\Cluster\DirectRemoteActorRef;
use Monadial\Nexus\Cluster\Router\DirectRouter;
use Monadial\Nexus\Cluster\Router\MessageRouter;
use Monadial\Nexus\Cluster\SwooleThread\Directory\ThreadMapDirectory;
use Monadial\Nexus\Cluster\SwooleThread\Transport\ThreadQueueTransport;
use Monadial\Nexus\Cluster\Transport\EnvelopeTransport;
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
use Swoole\Thread\Map;
use Swoole\Thread\Queue;

use function Swoole\Coroutine\run;

/**
 * End-to-end integration test for the thread-based cluster stack.
 *
 * Simulates a multi-worker thread cluster within a single Swoole coroutine context
 * using Thread\Queue and Thread\Map objects. Tests the full path:
 *   ClusterNode -> ConsistentHashRing -> DirectRemoteActorRef -> ThreadQueueTransport
 *   -> direct envelope delivery to local actors (no serialization).
 *
 * Each "worker" has its own ActorSystem + SwooleRuntime + ThreadQueueTransport,
 * communicating over shared Thread\Queue instances.
 *
 * @psalm-suppress UndefinedClass
 */
#[CoversClass(ClusterNode::class)]
#[RequiresPhpExtension('swoole')]
final class ThreadClusterE2ETest extends TestCase
{
    #[Test]
    public function crossWorkerMessageDeliveryViaThreadQueues(): void
    {
        if (!class_exists(Queue::class)) {
            self::markTestSkipped('Swoole Thread\\Queue requires ZTS build with --enable-swoole-thread');
        }

        $received = [];

        /** @psalm-suppress UnusedFunctionCall, UndefinedClass, MixedMethodCall */
        run(function () use (&$received): void {
            $workerCount = 2;
            $ring = new ConsistentHashRing($workerCount);

            /** @psalm-suppress UndefinedClass */
            $map = new Map();

            /** @psalm-suppress UndefinedClass */
            $queues = [new Queue(), new Queue()];

            $directory = new ThreadMapDirectory($map);

            // Create transports: each worker reads from its own queue
            $transport0 = new ThreadQueueTransport($queues, 0);
            $transport1 = new ThreadQueueTransport($queues, 1);

            // Create runtimes and mark them as inside Co\run() so spawn() creates
            // coroutines immediately instead of queueing them as pending.
            $runtime0 = $this->createActiveRuntime();
            $runtime1 = $this->createActiveRuntime();

            $system0 = ActorSystem::create('worker-0', $runtime0);
            $system1 = ActorSystem::create('worker-1', $runtime1);

            // Create routers and cluster nodes
            $router0 = new DirectRouter($transport0);
            $router1 = new DirectRouter($transport1);
            $node0 = $this->createClusterNode(0, $system0, $router0, $ring, $directory, $transport0);
            $node1 = $this->createClusterNode(1, $system1, $router1, $ring, $directory, $transport1);

            // Start listening for incoming transport messages in coroutines
            // DirectRouter::startReceiving() loops, so it must run in a coroutine.
            Coroutine::create(static fn() => $node0->start());
            Coroutine::create(static fn() => $node1->start());

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

            // Spawn actors -- each on the node that owns it (local spawn)
            $ref0on0 = $node0->spawn(Props::fromBehavior($sinkBehavior), $nameForWorker0);
            $ref1on1 = $node1->spawn(Props::fromBehavior($sinkBehavior), $nameForWorker1);

            // Verify local refs are LocalActorRef
            self::assertInstanceOf(LocalActorRef::class, $ref0on0);
            self::assertInstanceOf(LocalActorRef::class, $ref1on1);

            // Get remote refs: node 0 looking up actor on worker 1, and vice versa
            $ref1on0 = $node0->actorFor("/user/{$nameForWorker1}");
            $ref0on1 = $node1->actorFor("/user/{$nameForWorker0}");

            self::assertInstanceOf(DirectRemoteActorRef::class, $ref1on0);
            self::assertInstanceOf(DirectRemoteActorRef::class, $ref0on1);

            // Send cross-worker messages via DirectRemoteActorRef -> transport -> direct delivery
            // From node 0 -> actor on worker 1
            $ref1on0->tell((object) ['payload' => 'from-worker-0']);
            // From node 1 -> actor on worker 0
            $ref0on1->tell((object) ['payload' => 'from-worker-1']);

            // Allow coroutines time to deliver and process messages
            Coroutine::sleep(0.3);

            // Cleanup
            $router0->close();
            $router1->close();
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
    public function directoryRegistrationDuringSpawn(): void
    {
        if (!class_exists(Queue::class)) {
            self::markTestSkipped('Swoole Thread\\Queue requires ZTS build with --enable-swoole-thread');
        }

        /** @psalm-suppress UnusedFunctionCall, UndefinedClass, MixedMethodCall */
        run(function (): void {
            $workerCount = 2;
            $ring = new ConsistentHashRing($workerCount);

            /** @psalm-suppress UndefinedClass */
            $map = new Map();

            /** @psalm-suppress UndefinedClass */
            $queues = [new Queue(), new Queue()];

            $directory = new ThreadMapDirectory($map);

            $transport0 = new ThreadQueueTransport($queues, 0);
            $transport1 = new ThreadQueueTransport($queues, 1);

            $runtime0 = $this->createActiveRuntime();
            $runtime1 = $this->createActiveRuntime();
            $system0 = ActorSystem::create('worker-0', $runtime0);
            $system1 = ActorSystem::create('worker-1', $runtime1);

            $router0 = new DirectRouter($transport0);
            $router1 = new DirectRouter($transport1);
            $node0 = $this->createClusterNode(0, $system0, $router0, $ring, $directory, $transport0);
            $node1 = $this->createClusterNode(1, $system1, $router1, $ring, $directory, $transport1);

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
            self::assertInstanceOf(DirectRemoteActorRef::class, $remoteRef);

            // Unknown actor should return null
            self::assertNull($node0->actorFor('/user/nonexistent'));

            $router0->close();
            $router1->close();
            $system0->shutdown(Duration::millis(100));
            $system1->shutdown(Duration::millis(100));
        });
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
        EnvelopeTransport $transport,
    ): ClusterNode {
        /** @var callable(ActorPath, int): ActorRef<object> $remoteRefFactory */
        $remoteRefFactory = static fn(ActorPath $path, int $targetWorker): ActorRef => new DirectRemoteActorRef(
            $path,
            $targetWorker,
            $transport,
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
