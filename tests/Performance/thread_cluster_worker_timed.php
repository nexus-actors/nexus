<?php

/**
 * Timed worker script for Thread Cluster benchmark.
 * Sends messages continuously until the stop signal is set.
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
use Swoole\Thread\Atomic\Long;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;

use function Swoole\Coroutine\run;

[
    $workerId,
    $workerCount,
    $map,
    $queues,
    $delivered,
    $sent,
    $ready,
    $startSignal,
    $stopSignal,
    $results,
] = Thread::getArguments();

/** @var int $workerId */
/** @var int $workerCount */
/** @var Map $map */
/** @var Map $queues */
/** @var Long $delivered */
/** @var Long $sent */
/** @var Atomic $ready */
/** @var Atomic $startSignal */
/** @var Atomic $stopSignal */
/** @var Map $results */

run(static function () use (
    $workerId,
    $workerCount,
    $map,
    $queues,
    $delivered,
    $sent,
    $ready,
    $startSignal,
    $stopSignal,
    $results,
): void {
    /** @var list<Queue> $queueList */
    $queueList = [];

    for ($i = 0; $i < $workerCount; $i++) {
        $queueList[] = $queues[$i];
    }

    $runtime = new SwooleRuntime();
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

    // Start receive loop
    Coroutine::create(static function () use ($node): void {
        $node->start();
    });

    // Spawn sink actor
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

    while ($ready->get() < $workerCount) {
        Coroutine::sleep(0.001);
    }

    // Resolve remote refs for all other workers upfront
    $targetNames = [];

    for ($w = 0; $w < $workerCount; $w++) {
        if ($w !== $workerId) {
            $targetNames[$w] = findNameForWorker($ring, $w);
        }
    }

    $targetWorkerIds = array_keys($targetNames);
    $targetCount = count($targetWorkerIds);

    // Pre-resolve refs
    $refs = [];

    foreach ($targetWorkerIds as $tw) {
        $refs[$tw] = $node->actorFor("/user/{$targetNames[$tw]}");
    }

    // Wait for start signal
    while ($startSignal->get() === 0) {
        Coroutine::sleep(0.001);
    }

    // Send continuously until stop signal
    $localSent = 0;
    $batchSize = 100;

    while ($stopSignal->get() === 0) {
        // Send a batch, then yield
        for ($b = 0; $b < $batchSize; $b++) {
            $tw = $targetWorkerIds[$localSent % $targetCount];
            $ref = $refs[$tw];

            if ($ref !== null) {
                $ref->tell((object) ['s' => $localSent]);
            }

            $localSent++;
        }

        $sent->add($batchSize);

        // Yield to let receive coroutine process
        Coroutine::sleep(0.001);
    }

    // Record this worker's sent count
    $results["sent_{$workerId}"] = $localSent;

    // Wait for remaining messages to drain (up to 5s)
    $drainStart = hrtime(true);

    while ($delivered->get() < $sent->get() && (hrtime(true) - $drainStart) < 5_000_000_000) {
        Coroutine::sleep(0.01);
    }

    // Worker 0 records final stats
    if ($workerId === 0) {
        Coroutine::sleep(0.5);
        $results['final_delivered'] = $delivered->get();
        $results['final_sent'] = $sent->get();
    }

    Coroutine::sleep(0.3);

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
