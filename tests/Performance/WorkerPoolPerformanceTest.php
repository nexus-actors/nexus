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
use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
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
     * Cross-thread actor throughput: N messages delivered to a real Swoole actor system.
     *
     * Boots a 2-worker thread pool using Swoole\Thread directly (not Thread\Pool, which
     * blocks the calling thread). Each thread runs a full ActorSystem + WorkerNode with
     * one "sink" actor that increments a shared Thread\Atomic counter per message.
     *
     * The main test thread injects envelopes directly into the workers' Thread\Queues,
     * bypassing the WorkerActorRef layer. This isolates the actor dispatch path (queue
     * pop → listener → localRefs lookup → Channel push → coroutine wake → handler).
     *
     * Thread coordination:
     *   - $readyCounter — workers increment this on startup; main thread polls until >= 2
     *   - $sinkPaths    — workers publish their sink actor path; main thread reads it
     *   - $stopSignal   — main thread sets to 1 after measurement; workers shut down
     *
     * Target: >50K cross-thread actor messages/sec.
     *
     * Run: docker compose run --rm php-swoole vendor/bin/phpunit --testsuite=performance --filter=testThreadedActor
     */
    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function testThreadedActorThroughput(): void
    {
        $workerCount  = 2;
        $messageCount = 5_000;
        $workerScript = __DIR__ . '/thread_worker_script.php';

        // Shared thread-safe objects — the ONLY types that cross thread boundaries.
        $directory      = new Map();
        $workerIdCounter = new Atomic(0);
        $messageCounter = new Atomic(0);
        $readyCounter   = new Atomic(0);
        $sinkPaths      = new Map();
        $stopSignal     = new Atomic(0);

        /** @var array<int, Queue> $queues */
        $queues = [];

        for ($i = 0; $i < $workerCount; $i++) {
            $queues[$i] = new Queue();
        }

        $autoloader = $this->findAutoloader();

        // Start worker threads. Swoole\Thread is non-blocking — the thread runs
        // in the background and the main thread continues immediately.
        /** @var list<\Swoole\Thread> $threads */
        $threads = [];

        for ($i = 0; $i < $workerCount; $i++) {
            /** @psalm-suppress UndefinedClass */
            $threads[] = new Thread(
                $workerScript,
                $autoloader,
                $directory,
                $queues,
                $workerIdCounter,
                $workerCount,
                $messageCounter,
                $readyCounter,
                $sinkPaths,
                $stopSignal,
            );
        }

        // Wait for all workers to finish startup (max 15s).
        $deadline = time() + 15;

        while ($readyCounter->get() < $workerCount) {
            if (time() > $deadline) {
                $stopSignal->set(1);

                foreach ($threads as $thread) {
                    /** @psalm-suppress UndefinedClass */
                    $thread->join();
                }

                self::fail('Worker threads did not become ready within 15 seconds');
            }

            usleep(10_000); // 10ms poll
        }

        // Pick the sink path for worker 1 (any worker would do).
        // Key 1 = worker 1's sink path (set by $sinkPaths[$workerId] = "/user/..." in thread script).
        /** @psalm-suppress InvalidArgument */
        $sinkPath = isset($sinkPaths[1])
            ? (string) $sinkPaths[1]
            : '';

        if ($sinkPath === '' && isset($sinkPaths[0])) {
            /** @psalm-suppress InvalidArgument */
            $sinkPath = (string) $sinkPaths[0];
            $targetWorkerForSink = 0;
        } else {
            $targetWorkerForSink = 1;
        }

        self::assertNotEmpty($sinkPath, 'No sink path published by workers');

        $targetQueue = $queues[$targetWorkerForSink] ?? null;
        self::assertNotNull($targetQueue, "No queue for worker {$targetWorkerForSink}");

        $targetActorPath = ActorPath::fromString($sinkPath);
        $senderPath      = ActorPath::root();

        // Inject N envelopes directly to the target worker's inbox.
        $start = hrtime(true);

        for ($i = 0; $i < $messageCount; $i++) {
            $envelope = Envelope::of(new stdClass(), $senderPath, $targetActorPath);
            $targetQueue->push($envelope, Queue::NOTIFY_ONE);
        }

        // Wait for the sink actor to process all messages (max 30s).
        $deadline = time() + 30;

        while ($messageCounter->get() < $messageCount) {
            if (time() > $deadline) {
                $stopSignal->set(1);

                foreach ($threads as $thread) {
                    /** @psalm-suppress UndefinedClass */
                    $thread->join();
                }

                self::fail(sprintf(
                    'Timed out: only %d/%d messages processed after 30s',
                    $messageCounter->get(),
                    $messageCount,
                ));
            }

            usleep(5_000); // 5ms poll
        }

        /** @psalm-suppress InvalidOperand */
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        // Signal workers to shut down.
        $stopSignal->set(1);

        foreach ($threads as $thread) {
            /** @psalm-suppress UndefinedClass */
            $thread->join();
        }

        /** @psalm-suppress InvalidOperand */
        $opsPerSecond = $elapsedMs > 0.0
            ? (float) $messageCount / $elapsedMs * 1000.0
            : 0.0;

        $usPerMessage = $elapsedMs > 0.0
            ? $elapsedMs * 1000.0 / (float) $messageCount
            : 0.0;

        fwrite(STDERR, sprintf(
            "\n  [WorkerPool: %s cross-thread actor messages via Swoole threads] %.1fms (%.0f msg/sec, %.1fμs/msg)\n",
            number_format($messageCount),
            $elapsedMs,
            $opsPerSecond,
            $usPerMessage,
        ));

        self::assertGreaterThan(0, $opsPerSecond);
        self::assertSame($messageCount, $messageCounter->get(), 'Not all messages were processed');
    }

    /**
     * Cross-thread actor latency: derived per-message latency from small burst.
     *
     * Boots the same 2-worker thread pool as testThreadedActorThroughput but uses a
     * smaller burst (500 messages) to measure per-message latency without batching
     * amortization. Latency is derived as: total_elapsed / message_count.
     *
     * Note: This is not a true point-to-point latency measurement (which would require
     * per-message timestamps). It measures the average end-to-end latency across a burst,
     * including queue push + coroutine wakeup + actor handler + Atomic increment.
     *
     * Target: <500μs average end-to-end cross-thread latency.
     *
     * Run: docker compose run --rm php-swoole vendor/bin/phpunit --testsuite=performance --filter=testThreadedActor
     */
    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function testThreadedActorLatency(): void
    {
        $workerCount  = 2;
        $messageCount = 500;
        $workerScript = __DIR__ . '/thread_worker_script.php';

        $directory       = new Map();
        $workerIdCounter = new Atomic(0);
        $messageCounter  = new Atomic(0);
        $readyCounter    = new Atomic(0);
        $sinkPaths       = new Map();
        $stopSignal      = new Atomic(0);

        /** @var array<int, Queue> $queues */
        $queues = [];

        for ($i = 0; $i < $workerCount; $i++) {
            $queues[$i] = new Queue();
        }

        $autoloader = $this->findAutoloader();

        /** @var list<\Swoole\Thread> $threads */
        $threads = [];

        for ($i = 0; $i < $workerCount; $i++) {
            /** @psalm-suppress UndefinedClass */
            $threads[] = new Thread(
                $workerScript,
                $autoloader,
                $directory,
                $queues,
                $workerIdCounter,
                $workerCount,
                $messageCounter,
                $readyCounter,
                $sinkPaths,
                $stopSignal,
            );
        }

        // Wait for all workers to become ready (max 15s).
        $deadline = time() + 15;

        while ($readyCounter->get() < $workerCount) {
            if (time() > $deadline) {
                $stopSignal->set(1);

                foreach ($threads as $thread) {
                    /** @psalm-suppress UndefinedClass */
                    $thread->join();
                }

                self::fail('Worker threads did not become ready within 15 seconds');
            }

            usleep(10_000);
        }

        /** @psalm-suppress InvalidArgument */
        $sinkPath = isset($sinkPaths[1])
            ? (string) $sinkPaths[1]
            : '';

        if ($sinkPath === '' && isset($sinkPaths[0])) {
            /** @psalm-suppress InvalidArgument */
            $sinkPath = (string) $sinkPaths[0];
            $targetWorkerForSink = 0;
        } else {
            $targetWorkerForSink = 1;
        }

        self::assertNotEmpty($sinkPath, 'No sink path published by workers');

        $targetQueue = $queues[$targetWorkerForSink] ?? null;
        self::assertNotNull($targetQueue, "No queue for worker {$targetWorkerForSink}");

        $targetActorPath = ActorPath::fromString($sinkPath);
        $senderPath      = ActorPath::root();

        // Measure total elapsed for a small burst.
        $start = hrtime(true);

        for ($i = 0; $i < $messageCount; $i++) {
            $envelope = Envelope::of(new stdClass(), $senderPath, $targetActorPath);
            $targetQueue->push($envelope, Queue::NOTIFY_ONE);
        }

        $deadline = time() + 30;

        while ($messageCounter->get() < $messageCount) {
            if (time() > $deadline) {
                $stopSignal->set(1);

                foreach ($threads as $thread) {
                    /** @psalm-suppress UndefinedClass */
                    $thread->join();
                }

                self::fail(sprintf(
                    'Timed out: only %d/%d messages processed',
                    $messageCounter->get(),
                    $messageCount,
                ));
            }

            usleep(1_000); // 1ms poll — tighter for latency test
        }

        /** @psalm-suppress InvalidOperand */
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $stopSignal->set(1);

        foreach ($threads as $thread) {
            /** @psalm-suppress UndefinedClass */
            $thread->join();
        }

        $avgUsPerMessage = $elapsedMs > 0.0
            ? $elapsedMs * 1000.0 / (float) $messageCount
            : 0.0;

        /** @psalm-suppress InvalidOperand */
        $opsPerSecond = $elapsedMs > 0.0
            ? (float) $messageCount / $elapsedMs * 1000.0
            : 0.0;

        fwrite(STDERR, sprintf(
            "\n  [WorkerPool: %s cross-thread latency burst] %.1fms total | %.1fμs avg/msg | %.0f msg/sec\n",
            number_format($messageCount),
            $elapsedMs,
            $avgUsPerMessage,
            $opsPerSecond,
        ));

        self::assertGreaterThan(0, $opsPerSecond);
        self::assertSame($messageCount, $messageCounter->get(), 'Not all messages were processed');
    }

    /**
     * Resolve the path to vendor/autoload.php.
     */
    private function findAutoloader(): string
    {
        foreach (get_included_files() as $file) {
            if (str_ends_with($file, 'vendor/autoload.php')) {
                return $file;
            }
        }

        // Fallback: walk up from this file.
        $dir = __DIR__;

        while ($dir !== '/') {
            $candidate = $dir . '/vendor/autoload.php';

            if (file_exists($candidate)) {
                return $candidate;
            }

            $dir = dirname($dir);
        }

        self::fail('Could not locate vendor/autoload.php');
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
