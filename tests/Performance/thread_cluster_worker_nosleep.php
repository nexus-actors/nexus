<?php

/**
 * Worker variant: uses unbuffered Channel for yielding instead of sleep(0.001).
 * Tests the theoretical throughput ceiling when the 1ms sleep floor is removed.
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
use Monadial\Nexus\Cluster\Transport\EnvelopeTransport;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\Envelope;
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

    // Custom fast transport: uses Channel yield instead of sleep
    $myQueue = $queueList[$workerId];
    $closed = false;

    // Yield channel: unbuffered, forces context switch in ~0.3μs (vs 1440μs for sleep)
    $yieldCh = new Coroutine\Channel(0);

    // Ticker coroutine: continuously pops from yield channel to enable context switch
    Coroutine::create(static function () use ($yieldCh, &$closed): void {
        while (!$closed) {
            $yieldCh->pop(0.1);
        }
    });

    // Build a transport that uses Channel-based yield
    $transport = new class ($queueList, $workerId, $myQueue, $yieldCh, $closed) implements EnvelopeTransport {
        private bool $localClosed;

        /** @param list<Queue> $queues */
        public function __construct(
            private readonly array $queues,
            private readonly int $workerId,
            private readonly Queue $myQueue,
            private readonly Coroutine\Channel $yieldCh,
            private bool &$closedRef,
        ) {
            $this->localClosed = false;
        }

        public function send(int $targetWorker, Envelope $envelope): void
        {
            if ($this->localClosed) {
                return;
            }

            $this->queues[$targetWorker]->push($envelope, 0);
        }

        public function receive(): Envelope
        {
            $emptyCount = 0;

            while (!$this->localClosed) {
                $envelope = $this->myQueue->pop(0);

                if ($envelope !== null) {
                    return $envelope;
                }

                $emptyCount++;

                if ($emptyCount < 50) {
                    // Fast yield via channel (~0.3μs)
                    $this->yieldCh->push(true, 0.001);
                } else {
                    // Fallback to sleep for idle
                    Coroutine::sleep(0.001);
                }
            }

            throw new RuntimeException('closed');
        }

        public function close(): void
        {
            $this->localClosed = true;
            $this->closedRef = true;
            $this->myQueue->clean();
        }
    };

    $router = new DirectRouter($transport);

    $remoteRefFactory = static fn(ActorPath $path, int $targetWorker) => new DirectRemoteActorRef(
        $path,
        $targetWorker,
        $transport,
        $directory,
    );

    $node = new ClusterNode($workerId, $system, $router, $ring, $directory, $remoteRefFactory);

    Coroutine::create(static function () use ($node): void {
        $node->start();
    });

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

    // Send with channel-based yield instead of sleep
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

        // Fast yield via channel (~0.3μs) instead of sleep (1440μs)
        $yieldCh->push(true, 0.001);
    }

    $results["sent_{$workerId}"] = $localSent;

    $drainStart = hrtime(true);

    while ($delivered->get() < $sent->get() && (hrtime(true) - $drainStart) < 5_000_000_000) {
        Coroutine::sleep(0.01);
    }

    if ($workerId === 0) {
        Coroutine::sleep(0.5);
        $results['final_delivered'] = $delivered->get();
        $results['final_sent'] = $sent->get();
    }

    Coroutine::sleep(0.3);
    $closed = true;
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
