<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance;

use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use Monadial\Nexus\Cluster\Directory\InMemoryDirectory;
use Monadial\Nexus\Cluster\RemoteActorRef;
use Monadial\Nexus\Cluster\Router\MessageRouter;
use Monadial\Nexus\Cluster\Router\SerializingRouter;
use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Cluster\Serialization\CompactClusterSerializer;
use Monadial\Nexus\Cluster\Swoole\Transport\UnixSocketTransport;
use Monadial\Nexus\Cluster\Transport\Transport;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Override;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

/**
 * Performance benchmarks for the cluster IPC stack.
 *
 * Measures cross-worker message throughput, round-trip latency, serialization
 * performance, and multi-worker fan-out through real Unix domain sockets.
 *
 * Non-functional requirements:
 *   - Cross-worker throughput: >100K msgs/sec per worker pair
 *   - Cross-worker round-trip latency: <100us p99
 *   - Serialization: >1M roundtrips/sec
 *
 * Run: docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance --filter=Cluster
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[RequiresPhpExtension('swoole')]
final class ClusterPerformanceTest extends TestCase
{
    private string $socketDir;

    /**
     * Cross-worker message throughput: send 10K messages from worker 0
     * to an actor on worker 1 via UnixSocketTransport, measure ops/sec.
     */
    #[Test]
    public function crossWorkerMessageThroughput(): void
    {
        $messageCount = 10_000;
        $elapsedMs = 0.0;
        $delivered = 0;

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use (&$elapsedMs, &$delivered, $messageCount): void {
            $workerCount = 2;
            $ring = new ConsistentHashRing($workerCount);
            $serializer = new CompactClusterSerializer();
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

            $nameForWorker1 = $this->findNameForWorker($ring, 1);

            // Sink actor on worker 1 that counts delivered messages
            /** @psalm-suppress InvalidArgument */
            $sinkBehavior = Behavior::receive(
                static function (ActorContext $_ctx, object $_msg) use (&$delivered): Behavior {
                    $delivered++;

                    return Behavior::same();
                },
            );
            $node1->spawn(Props::fromBehavior($sinkBehavior), $nameForWorker1);

            // Remote ref from node 0 to actor on worker 1
            $remoteRef = $node0->actorFor("/user/{$nameForWorker1}");
            self::assertInstanceOf(RemoteActorRef::class, $remoteRef);

            // Send all messages and measure time
            $start = hrtime(true);

            for ($i = 0; $i < $messageCount; $i++) {
                $remoteRef->tell((object) ['seq' => $i]);
            }

            // Wait for all messages to be delivered
            $maxWait = 10.0;
            $waited = 0.0;

            while ($delivered < $messageCount && $waited < $maxWait) {
                Coroutine::sleep(0.01);
                $waited += 0.01;
            }

            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            $transport0->close();
            $transport1->close();
            $system0->shutdown(Duration::millis(100));
            $system1->shutdown(Duration::millis(100));
        });

        /** @psalm-suppress InvalidOperand */
        $opsPerSecond = $elapsedMs > 0.0
            ? (float) $messageCount / $elapsedMs * 1000.0
            : 0.0;

        fwrite(STDERR, sprintf(
            "\n  [Cluster: %s cross-worker messages] %.1fms (%.0f ops/sec, %d delivered)\n",
            number_format($messageCount),
            $elapsedMs,
            $opsPerSecond,
            $delivered,
        ));

        self::assertGreaterThan(0, $opsPerSecond);
    }

    /**
     * Cross-worker round-trip latency: ping-pong between actors on different
     * workers via Unix sockets, measure us/roundtrip.
     */
    #[Test]
    public function crossWorkerRoundTripLatency(): void
    {
        $rounds = 1_000;
        $elapsedMs = 0.0;
        $completed = 0;

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use (&$elapsedMs, &$completed, $rounds): void {
            $workerCount = 2;
            $ring = new ConsistentHashRing($workerCount);
            $serializer = new CompactClusterSerializer();
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

            // Pong actor on worker 1: receives ping, sends reply to the remote ref on worker 0
            /** @psalm-suppress InvalidArgument */
            $pongBehavior = Behavior::receive(
                static function (ActorContext $_ctx, object $_msg) use ($node1, $nameForWorker0): Behavior {
                    $replyRef = $node1->actorFor("/user/{$nameForWorker0}");

                    if ($replyRef !== null) {
                        $replyRef->tell((object) ['type' => 'pong']);
                    }

                    return Behavior::same();
                },
            );
            $node1->spawn(Props::fromBehavior($pongBehavior), $nameForWorker1);

            // Ping actor on worker 0: receives pong, sends next ping to worker 1
            /** @psalm-suppress InvalidArgument */
            $pingBehavior = Behavior::receive(
                static function (ActorContext $_ctx, object $_msg) use (&$completed, $node0, $nameForWorker1, $rounds): Behavior {
                    $completed++;

                    if ($completed < $rounds) {
                        $remoteRef = $node0->actorFor("/user/{$nameForWorker1}");

                        if ($remoteRef !== null) {
                            $remoteRef->tell((object) ['type' => 'ping']);
                        }
                    }

                    return Behavior::same();
                },
            );
            $node0->spawn(Props::fromBehavior($pingBehavior), $nameForWorker0);

            // Kick off the first ping from worker 0 -> worker 1
            $remoteToWorker1 = $node0->actorFor("/user/{$nameForWorker1}");
            self::assertInstanceOf(RemoteActorRef::class, $remoteToWorker1);

            $start = hrtime(true);
            $remoteToWorker1->tell((object) ['type' => 'ping']);

            // Wait for all rounds to complete
            $maxWait = 30.0;
            $waited = 0.0;

            while ($completed < $rounds && $waited < $maxWait) {
                Coroutine::sleep(0.01);
                $waited += 0.01;
            }

            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            $transport0->close();
            $transport1->close();
            $system0->shutdown(Duration::millis(100));
            $system1->shutdown(Duration::millis(100));
        });

        /** @psalm-suppress InvalidOperand */
        $opsPerSecond = $elapsedMs > 0.0
            ? (float) $completed / $elapsedMs * 1000.0
            : 0.0;
        /** @psalm-suppress InvalidOperand */
        $usPerRoundTrip = $completed > 0
            ? $elapsedMs * 1000.0 / (float) $completed
            : 0.0;

        fwrite(STDERR, sprintf(
            "\n  [Cluster: %s ping-pong round trips] %.1fms (%.0f ops/sec, %.1fus/roundtrip, %d completed)\n",
            number_format($rounds),
            $elapsedMs,
            $opsPerSecond,
            $usPerRoundTrip,
            $completed,
        ));

        self::assertGreaterThan(0, $opsPerSecond);
    }

    /**
     * Serialization throughput: pure serialize/deserialize cycle for Envelope,
     * no sockets involved. Uses Benchmark::measure() since no coroutines needed.
     */
    #[Test]
    public function serializationThroughput(): void
    {
        $iterations = 100_000;
        $serializer = new CompactClusterSerializer();
        $envelope = Envelope::of(
            (object) ['payload' => 'benchmark-data', 'seq' => 42],
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/target'),
        );

        $metrics = Benchmark::measure(
            "Cluster: {$iterations} serialize+deserialize cycles",
            $iterations,
            static function () use ($serializer, $envelope, $iterations): void {
                for ($i = 0; $i < $iterations; $i++) {
                    $data = $serializer->serialize($envelope);
                    (void) $serializer->deserialize($data);
                }
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Multi-worker fan-out: 4 workers, worker 0 sends 5K messages distributed
     * across actors on all 4 workers, measure aggregate throughput.
     */
    #[Test]
    public function multiWorkerFanOut(): void
    {
        $messageCount = 5_000;
        $elapsedMs = 0.0;
        $delivered = 0;

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use (&$elapsedMs, &$delivered, $messageCount): void {
            $workerCount = 4;
            $ring = new ConsistentHashRing($workerCount);
            $serializer = new CompactClusterSerializer();
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

            // Start all nodes
            for ($i = 0; $i < $workerCount; $i++) {
                $nodes[$i]->start();
            }

            // Find actor names for each worker and spawn sink actors
            $actorNames = [];

            for ($w = 0; $w < $workerCount; $w++) {
                $name = $this->findNameForWorker($ring, $w);
                $actorNames[$w] = $name;

                /** @psalm-suppress InvalidArgument */
                $sinkBehavior = Behavior::receive(
                    static function (ActorContext $_ctx, object $_msg) use (&$delivered): Behavior {
                        $delivered++;

                        return Behavior::same();
                    },
                );

                // Spawn on the owning node (local spawn)
                $nodes[$w]->spawn(Props::fromBehavior($sinkBehavior), $name);
            }

            // Worker 0 sends messages distributed across all actors
            $start = hrtime(true);

            for ($i = 0; $i < $messageCount; $i++) {
                $targetWorker = $i % $workerCount;
                $targetName = $actorNames[$targetWorker];
                $ref = $nodes[0]->actorFor("/user/{$targetName}");

                if ($ref !== null) {
                    $ref->tell((object) ['seq' => $i]);
                }
            }

            // Wait for delivery
            $maxWait = 15.0;
            $waited = 0.0;

            while ($delivered < $messageCount && $waited < $maxWait) {
                Coroutine::sleep(0.01);
                $waited += 0.01;
            }

            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            // Cleanup
            for ($i = 0; $i < $workerCount; $i++) {
                $transports[$i]->close();
                $systems[$i]->shutdown(Duration::millis(100));
            }
        });

        /** @psalm-suppress InvalidOperand */
        $opsPerSecond = $elapsedMs > 0.0
            ? (float) $messageCount / $elapsedMs * 1000.0
            : 0.0;

        fwrite(STDERR, sprintf(
            "\n  [Cluster: %s fan-out messages across 4 workers] %.1fms (%.0f ops/sec, %d delivered)\n",
            number_format($messageCount),
            $elapsedMs,
            $opsPerSecond,
            $delivered,
        ));

        self::assertGreaterThan(0, $opsPerSecond);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->socketDir = sys_get_temp_dir() . '/nexus-bench-' . (int) getmypid();

        if (!is_dir($this->socketDir)) {
            mkdir($this->socketDir, 0755, true);
        }
    }

    #[Override]
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
