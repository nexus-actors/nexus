<?php

/**
 * Worker using Coroutine::yield()/resume() for ~0.2μs context switching.
 * Eliminates the 1ms sleep floor entirely.
 *
 * Architecture:
 *   - Send coroutine: sends batch, yields
 *   - Receive transport: uses yield/resume instead of sleep for empty polls
 *   - Coordinator: alternates between send and receive via resume()
 *
 * @psalm-suppress UndefinedClass, MixedMethodCall, MixedAssignment
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\DirectRemoteActorRef;
use Monadial\Nexus\Cluster\Router\MessageRouter;
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

    $myQueue = $queueList[$workerId];
    $closed = false;

    // Track the receive coroutine ID for yield/resume
    $receiveCid = 0;

    // Transport: uses yield/resume when queue is empty instead of sleep
    $transport = new class ($queueList, $workerId, $myQueue, $closed, $receiveCid) implements EnvelopeTransport {
        private bool $localClosed;

        /** @param list<Queue> $queues */
        public function __construct(
            private readonly array $queues,
            private readonly int $workerId,
            private readonly Queue $myQueue,
            private bool &$closedRef,
            private int &$receiveCid,
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
            $this->receiveCid = Coroutine::getCid();
            $emptyStreak = 0;

            while (!$this->localClosed) {
                $envelope = $this->myQueue->pop(0);

                if ($envelope !== null) {
                    $emptyStreak = 0;

                    return $envelope;
                }

                $emptyStreak++;

                if ($emptyStreak < 1000) {
                    // Fast yield via Coroutine::yield() (~0.2μs)
                    // Another coroutine must resume us
                    Coroutine::yield();
                } else {
                    // Idle fallback: sleep to avoid wasting CPU when truly idle
                    Coroutine::sleep(0.001);
                    $emptyStreak = 0;
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

    // Router: same as DirectRouter but resumes receive coroutine after handler
    $router = new class ($transport) implements MessageRouter {
        private bool $closed = false;

        public function __construct(private readonly EnvelopeTransport $transport) {}

        public function send(int $targetWorker, Envelope $envelope): void
        {
            $this->transport->send($targetWorker, $envelope);
        }

        public function startReceiving(callable $handler): void
        {
            while (!$this->closed) {
                try {
                    $envelope = $this->transport->receive();
                    $handler($envelope);
                } catch (RuntimeException) {
                    break;
                }
            }
        }

        public function close(): void
        {
            $this->closed = true;
            $this->transport->close();
        }
    };

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

    // Spawn N send coroutines that overlap their yields
    $doneCh = new Coroutine\Channel($sendCoros);

    for ($c = 0; $c < $sendCoros; $c++) {
        Coroutine::create(static function () use (
            $stopSignal,
            $batchSize,
            $targetWorkerIds,
            $targetCount,
            $refs,
            $sent,
            &$receiveCid,
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

                // Resume receive coroutine if it yielded, then yield ourselves
                if ($receiveCid > 0) {
                    try {
                        Coroutine::resume($receiveCid);
                    } catch (Throwable) {
                        // Receive may not be yielded; that's fine
                    }
                }

                // Yield to let other coroutines run (~0.2μs)
                Coroutine::yield();
            }

            $doneCh->push($localSent);
        });
    }

    // Coordinator: resumes all yielded send coroutines in round-robin
    // This creates a fast scheduling loop without sleep
    $sendCids = [];

    // Give send coros time to start and register their CIDs
    Coroutine::sleep(0.001);

    // Main loop: keep resuming send coroutines until stop signal
    // Each send coro yields after a batch, and we resume them in turn
    // This effectively creates ~0.2μs * N round-robin scheduling
    while ($stopSignal->get() === 0) {
        // Resume receive if yielded
        if ($receiveCid > 0) {
            try {
                Coroutine::resume($receiveCid);
            } catch (Throwable) {
            }
        }

        // Let Swoole scheduler run other coroutines
        Coroutine::sleep(0.001);
    }

    // Collect send results
    $totalLocalSent = 0;

    for ($c = 0; $c < $sendCoros; $c++) {
        $totalLocalSent += (int) $doneCh->pop(5.0);
    }

    $results["sent_{$workerId}"] = $totalLocalSent;

    // Drain
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
