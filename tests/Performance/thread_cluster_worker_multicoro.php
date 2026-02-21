<?php

/**
 * Multi-coroutine worker: N send coroutines overlap their 1ms sleeps.
 * While coro 1 sleeps, coros 2-N send. Effective yield = 1ms/N.
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
    $batchSize,
    $sendCoros,
] = Thread::getArguments();

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
    $batchSize,
    $sendCoros,
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

    $ready->add(1);

    while ($ready->get() < $workerCount) {
        Coroutine::sleep(0.001);
    }

    // Pre-resolve targets
    $targetNames = [];

    for ($w = 0; $w < $workerCount; $w++) {
        if ($w !== $workerId) {
            $targetNames[$w] = findNameForWorker($ring, $w);
        }
    }

    $targetWorkerIds = array_keys($targetNames);
    $targetCount = count($targetWorkerIds);

    $refs = [];

    foreach ($targetWorkerIds as $tw) {
        $refs[$tw] = $node->actorFor("/user/{$targetNames[$tw]}");
    }

    while ($startSignal->get() === 0) {
        Coroutine::sleep(0.001);
    }

    // Spawn N send coroutines — they overlap: while one sleeps, others send
    $doneCh = new Coroutine\Channel($sendCoros);

    for ($c = 0; $c < $sendCoros; $c++) {
        Coroutine::create(static function () use (
            $stopSignal,
            $batchSize,
            $targetWorkerIds,
            $targetCount,
            $refs,
            $sent,
            $doneCh,
        ): void {
            $localSent = 0;

            while ($stopSignal->get() === 0) {
                for ($b = 0; $b < $batchSize; $b++) {
                    $tw = $targetWorkerIds[$localSent % $targetCount];
                    $ref = $refs[$tw];

                    if ($ref !== null) {
                        $ref->tell((object) ['s' => $localSent]);
                    }

                    $localSent++;
                }

                $sent->add($batchSize);
                Coroutine::sleep(0.001); // Other send coros run during this
            }

            $doneCh->push($localSent);
        });
    }

    // Wait for stop signal
    while ($stopSignal->get() === 0) {
        Coroutine::sleep(0.1);
    }

    // Collect results
    $totalLocalSent = 0;

    for ($c = 0; $c < $sendCoros; $c++) {
        $totalLocalSent += (int) $doneCh->pop(5.0);
    }

    $results["sent_{$workerId}"] = $totalLocalSent;

    // Drain
    $drainStart = hrtime(true);

    while ($delivered->get() < $sent->get() && (hrtime(true) - $drainStart) < 10_000_000_000) {
        Coroutine::sleep(0.01);
    }

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
