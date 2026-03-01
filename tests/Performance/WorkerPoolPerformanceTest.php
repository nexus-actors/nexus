<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use Monadial\Nexus\WorkerPool\WorkerActorRef;
use Monadial\Nexus\WorkerPool\WorkerNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Swoole\Coroutine;
use Swoole\Thread\Queue;

use function Swoole\Coroutine\run;

/**
 * Performance benchmarks for the thread-based worker pool.
 *
 * Measures hash ring lookup throughput, cross-worker tell() throughput,
 * actor spawn rate, directory lookup throughput, and thread queue I/O.
 *
 * Non-functional requirements:
 *   - Hash ring lookup: >1M ops/sec
 *   - Directory lookup: >1M ops/sec
 *   - WorkerActorRef::tell() via InMemory transport: >100K ops/sec
 *
 * Run: docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance --filter=WorkerPool
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[CoversNothing]
final class WorkerPoolPerformanceTest extends TestCase
{
    /**
     * Hash ring lookup throughput: 100K CRC32 hash ring lookups per second.
     *
     * Pure CPU, no I/O, no fibers. Measures the consistent hash ring overhead
     * when routing actor names to worker IDs.
     *
     * Target: >1M ops/sec.
     */
    #[Test]
    public function testHashRingDistribution(): void
    {
        $iterations = 100_000;
        $ring = new ConsistentHashRing(4);

        $metrics = Benchmark::measure(
            "WorkerPool: {$iterations} hash ring lookups (4 workers)",
            $iterations,
            static function () use ($ring, $iterations): void {
                for ($i = 0; $i < $iterations; $i++) {
                    /** @psalm-suppress UnusedMethodCall */
                    $ring->getWorker("actor-{$i}");
                }
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * InMemory WorkerActorRef::tell() throughput: 10K cross-worker messages.
     *
     * Sets up two WorkerNodes sharing an InMemoryWorkerTransport. Finds an
     * actor name that hashes to worker 1, spawns a behavior on node 1 (via
     * node 0's spawn routing), then measures time to enqueue 10K messages
     * via WorkerActorRef::tell() → InMemoryWorkerTransport::send().
     *
     * This measures the overhead of the WorkerNode routing layer and
     * WorkerActorRef without real thread I/O.
     */
    #[Test]
    public function testInMemoryWorkerTellThroughput(): void
    {
        $messageCount = 10_000;

        $ring = new ConsistentHashRing(2);
        $directory = new InMemoryWorkerDirectory();

        $transport0 = new InMemoryWorkerTransport();
        $transport1 = new InMemoryWorkerTransport();

        $runtime0 = new FiberRuntime();
        $runtime1 = new FiberRuntime();

        $system0 = ActorSystem::create('worker-0', $runtime0);
        $system1 = ActorSystem::create('worker-1', $runtime1);

        $node0 = new WorkerNode(0, $system0, $transport0, $ring, $directory);
        $node1 = new WorkerNode(1, $system1, $transport1, $ring, $directory);

        $nameForWorker1 = $this->findNameForWorker($ring, 1);

        /** @psalm-suppress InvalidArgument */
        $sinkBehavior = Behavior::receive(
            static fn(ActorContext $_ctx, object $_msg): Behavior => Behavior::same(),
        );

        // Spawn on node 1 directly so it's local (has a real LocalActorRef)
        $node1->spawn(Props::fromBehavior($sinkBehavior), $nameForWorker1);

        // From node 0's perspective this routes to a WorkerActorRef
        $ref = $node0->spawn(Props::fromBehavior($sinkBehavior), $nameForWorker1);
        self::assertInstanceOf(WorkerActorRef::class, $ref);

        $start = hrtime(true);

        for ($i = 0; $i < $messageCount; $i++) {
            /** @psalm-suppress NonSerializableRemoteMessage */
            $ref->tell(new stdClass());
        }

        /** @psalm-suppress InvalidOperand */
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        /** @psalm-suppress InvalidOperand */
        $opsPerSecond = $elapsedMs > 0.0
            ? (float) $messageCount / $elapsedMs * 1000.0
            : 0.0;

        fwrite(STDERR, sprintf(
            "\n  [WorkerPool: %s cross-worker tell() via InMemoryTransport] %.1fms (%.0f ops/sec)\n",
            number_format($messageCount),
            $elapsedMs,
            $opsPerSecond,
        ));

        self::assertGreaterThan(0, $opsPerSecond);

        $system0->shutdown(Duration::millis(100));
        $system1->shutdown(Duration::millis(100));
    }

    /**
     * WorkerNode::spawn() routing overhead: 1K actors, 50% local / 50% remote.
     *
     * Alternately spawns actors that hash to worker 0 (LocalActorRef) and
     * worker 1 (WorkerActorRef). Measures the routing logic overhead per spawn.
     */
    #[Test]
    public function testInMemoryWorkerSpawnRate(): void
    {
        $actorCount = 1_000;
        $ring = new ConsistentHashRing(2);
        $directory = new InMemoryWorkerDirectory();
        $transport = new InMemoryWorkerTransport();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('worker-0', $runtime);
        $node = new WorkerNode(0, $system, $transport, $ring, $directory);

        $namesForWorker0 = $this->findNamesForWorker($ring, 0, $actorCount / 2);
        $namesForWorker1 = $this->findNamesForWorker($ring, 1, $actorCount / 2);

        $allNames = [];

        for ($i = 0; $i < $actorCount / 2; $i++) {
            $allNames[] = $namesForWorker0[$i];
            $allNames[] = $namesForWorker1[$i];
        }

        /** @psalm-suppress InvalidArgument */
        $behavior = Behavior::receive(
            static fn(ActorContext $_ctx, object $_msg): Behavior => Behavior::same(),
        );

        $metrics = Benchmark::measure(
            "WorkerPool: {$actorCount} actor spawns via WorkerNode (50% local, 50% remote)",
            $actorCount,
            static function () use ($node, $behavior, $allNames): void {
                foreach ($allNames as $name) {
                    $node->spawn(Props::fromBehavior($behavior), $name);
                }
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);

        $system->shutdown(Duration::millis(100));
    }

    /**
     * InMemoryWorkerDirectory lookup throughput: 100K random lookups.
     *
     * Registers 1K paths, then measures lookup() and has() throughput across
     * 100K random path accesses. Simulates the hot path for actor routing.
     *
     * Target: >1M ops/sec.
     */
    #[Test]
    public function testDirectoryLookupThroughput(): void
    {
        $registrationCount = 1_000;
        $lookupCount = 100_000;

        $directory = new InMemoryWorkerDirectory();

        for ($i = 0; $i < $registrationCount; $i++) {
            $directory->register("/user/actor-{$i}", $i % 4);
        }

        $metrics = Benchmark::measure(
            "WorkerPool: {$lookupCount} directory lookups over {$registrationCount} registered paths",
            $lookupCount,
            static function () use ($directory, $lookupCount, $registrationCount): void {
                for ($i = 0; $i < $lookupCount; $i++) {
                    $idx = $i % $registrationCount;
                    $directory->lookup("/user/actor-{$idx}");
                }
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Swoole Thread\Queue push/pop throughput: 10K envelope pushes.
     *
     * Tests the raw Swoole\Thread\Queue throughput that backs ThreadQueueTransport.
     * This is the zero-serialization baseline for cross-thread message delivery.
     *
     * Note: Thread\Queue::pop() in non-blocking mode (timeout=0) is used since
     * blocking pop() blocks the entire OS thread. The queue is pre-filled before
     * timing to avoid measuring blocking reads.
     *
     * Full thread pool benchmark (WorkerPoolBootstrap + WorkerRunnable) requires
     * a custom WorkerPoolApp subclass — see WorkerPoolAppTest.php for that pattern.
     */
    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function testThreadQueuePushThroughput(): void
    {
        $messageCount = 10_000;
        $pushElapsedMs = 0.0;
        $popElapsedMs = 0.0;
        $popped = 0;

        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use ($messageCount, &$pushElapsedMs, &$popElapsedMs, &$popped): void {
            $queue = new Queue();

            $envelope = Envelope::of(
                new stdClass(),
                ActorPath::fromString('/user/sender'),
                ActorPath::fromString('/user/target'),
            );

            // Measure push throughput
            $pushStart = hrtime(true);

            for ($i = 0; $i < $messageCount; $i++) {
                $queue->push($envelope, Queue::NOTIFY_ONE);
            }

            /** @psalm-suppress InvalidOperand */
            $pushElapsedMs = (hrtime(true) - $pushStart) / 1_000_000;

            // Yield to let Swoole process any pending events
            Coroutine::sleep(0.001);

            // Measure pop throughput (non-blocking, queue is pre-filled)
            $popStart = hrtime(true);

            while (true) {
                /** @psalm-suppress MixedAssignment */
                $item = $queue->pop(0);

                if ($item === null) {
                    break;
                }

                $popped++;
            }

            /** @psalm-suppress InvalidOperand */
            $popElapsedMs = (hrtime(true) - $popStart) / 1_000_000;
        });

        /** @psalm-suppress InvalidOperand */
        $pushOpsPerSecond = $pushElapsedMs > 0.0
            ? (float) $messageCount / $pushElapsedMs * 1000.0
            : 0.0;

        /** @psalm-suppress InvalidOperand */
        $popOpsPerSecond = $popped > 0 && $popElapsedMs > 0.0
            ? (float) $popped / $popElapsedMs * 1000.0
            : 0.0;

        fwrite(STDERR, sprintf(
            "\n  [WorkerPool: Swoole\\Thread\\Queue %s pushes] %.1fms (%.0f push/sec)\n",
            number_format($messageCount),
            $pushElapsedMs,
            $pushOpsPerSecond,
        ));

        fwrite(STDERR, sprintf(
            "  [WorkerPool: Swoole\\Thread\\Queue %s pops] %.1fms (%.0f pop/sec)\n",
            number_format($popped),
            $popElapsedMs,
            $popOpsPerSecond,
        ));

        self::assertGreaterThan(0, $pushOpsPerSecond);
    }

    /**
     * Find the first actor name that hashes to the given worker ID.
     */
    private function findNameForWorker(ConsistentHashRing $ring, int $workerId): string
    {
        for ($i = 0; $i < 10_000; $i++) {
            $name = "actor-{$i}";

            if ($ring->getWorker($name) === $workerId) {
                return $name;
            }
        }

        self::fail("Could not find a name that hashes to worker {$workerId}");
    }

    /**
     * Find N distinct actor names that all hash to the given worker ID.
     *
     * @return list<string>
     */
    private function findNamesForWorker(ConsistentHashRing $ring, int $workerId, int $count): array
    {
        $names = [];
        $i = 0;

        while (count($names) < $count) {
            $name = "spawn-actor-{$workerId}-{$i}";
            $i++;

            if ($ring->getWorker($name) === $workerId) {
                $names[] = $name;
            }

            if ($i > 100_000) {
                self::fail("Could not find {$count} names that hash to worker {$workerId}");
            }
        }

        return $names;
    }
}
