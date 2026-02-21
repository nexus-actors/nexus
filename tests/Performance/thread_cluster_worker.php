<?php

/**
 * Worker script for Thread Cluster benchmark.
 * Each thread runs this script with shared objects passed via Thread::getArguments().
 *
 * @psalm-suppress UndefinedClass, MixedMethodCall, MixedAssignment
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\DirectRemoteActorRef;
use Monadial\Nexus\Cluster\Router\DirectRouter;
use Monadial\Nexus\Cluster\SwooleThread\Directory\ThreadMapDirectory;
use Monadial\Nexus\Cluster\SwooleThread\Transport\ThreadQueueTransport;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Coroutine;
use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;

use function Swoole\Coroutine\run;

[
    $workerId,
    $workerCount,
    $messagesPerWorker,
    $totalMessages,
    $map,
    $queues,
    $delivered,
    $ready,
    $startSignal,
    $results,
] = Thread::getArguments();

/** @var int $workerId */
/** @var int $workerCount */
/** @var int $messagesPerWorker */
/** @var int $totalMessages */
/** @var Map $map */
/** @var Map $queues - Map<int, Queue> since arrays can't cross threads */
/** @var Atomic $delivered */
/** @var Atomic $ready */
/** @var Atomic $startSignal */
/** @var Map $results */

run(static function () use (
    $workerId,
    $workerCount,
    $messagesPerWorker,
    $totalMessages,
    $map,
    $queues,
    $delivered,
    $ready,
    $startSignal,
    $results,
): void {
    // Build queue array from shared Map
    /** @var list<Queue> $queueList */
    $queueList = [];

    for ($i = 0; $i < $workerCount; $i++) {
        $queueList[] = $queues[$i];
    }

    $runtime = new SwooleRuntime();

    // Mark runtime as inside Co\run() so spawn() creates coroutines immediately
    $prop = new ReflectionProperty(SwooleRuntime::class, 'insideCoRun');
    $prop->setValue($runtime, true);

    $system = ActorSystem::create("worker-{$workerId}", $runtime);

    $directory = new ThreadMapDirectory($map);
    $ring = new ConsistentHashRing($workerCount);
    $transport = new ThreadQueueTransport($queueList, $workerId);
    $router = new DirectRouter($transport);

    $remoteRefFactory = static fn(ActorPath $path, int $targetWorker) => new DirectRemoteActorRef(
        $path,
        $targetWorker,
        $transport,
        $directory,
    );

    $node = new ClusterNode($workerId, $system, $router, $ring, $directory, $remoteRefFactory);

    // Start receive loop in dedicated coroutine
    Coroutine::create(static function () use ($node): void {
        $node->start();
    });

    // Find actor name for this worker and spawn sink actor
    $myName = findNameForWorker($ring, $workerId);

    /** @psalm-suppress InvalidArgument */
    $sinkBehavior = Behavior::receive(
        static function (ActorContext $_ctx, object $_msg) use ($delivered): Behavior {
            $delivered->add(1);

            return Behavior::same();
        },
    );
    $node->spawn(Props::fromBehavior($sinkBehavior), $myName);

    // Signal ready
    $ready->add(1);

    // Wait for all workers to be ready
    while ($ready->get() < $workerCount) {
        Coroutine::sleep(0.001);
    }

    // Find target actor names for all other workers
    $targetNames = [];

    for ($w = 0; $w < $workerCount; $w++) {
        if ($w !== $workerId) {
            $targetNames[$w] = findNameForWorker($ring, $w);
        }
    }

    $targetWorkerIds = array_keys($targetNames);

    // Pre-resolve refs before the timed loop
    $targetRefs = [];

    foreach ($targetNames as $w => $name) {
        $targetRefs[$w] = $node->actorFor("/user/{$name}");
    }

    // Wait for global start signal
    while ($startSignal->get() === 0) {
        Coroutine::sleep(0.001);
    }

    // Benchmark: send messagesPerWorker messages to other workers (round-robin)
    $targetCount = count($targetWorkerIds);

    for ($i = 0; $i < $messagesPerWorker; $i++) {
        $targetWorker = $targetWorkerIds[$i % $targetCount];
        $targetRefs[$targetWorker]->tell((object) ['seq' => $i, 'from' => $workerId]);
    }

    // All workers wait for all messages to be delivered
    $maxWait = 60.0;
    $waited = 0.0;

    while ($delivered->get() < $totalMessages && $waited < $maxWait) {
        Coroutine::sleep(0.001);
        $waited += 0.001;
    }

    // Small delay to let all workers see the final count
    Coroutine::sleep(0.01);

    // Worker 0 records final delivery count
    if ($workerId === 0) {
        $results['delivered'] = $delivered->get();
    }

    // Cleanup
    $router->close();
    $system->shutdown(Duration::millis(500));
});

function findNameForWorker(ConsistentHashRing $ring, int $workerId): string
{
    for ($i = 0; $i < 100_000; $i++) {
        $name = "actor-{$i}";

        if ($ring->getWorker($name) === $workerId) {
            return $name;
        }
    }

    throw new RuntimeException("Could not find a name that hashes to worker {$workerId}");
}
