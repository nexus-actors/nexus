<?php

/**
 * Profiled worker: instruments each stage of the hot path to find the real bottleneck.
 * Reports per-stage nanosecond timings.
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

    // === INSTRUMENTED TRANSPORT ===
    // Probe: queue push time, queue pop time, sleep/yield time
    $pushTimeNs = 0;
    $popTimeNs = 0;
    $sleepTimeNs = 0;
    $popCount = 0;
    $emptyPopCount = 0;

    $transport = new class ($queueList, $workerId, $myQueue, $pushTimeNs, $popTimeNs, $sleepTimeNs, $popCount, $emptyPopCount) implements EnvelopeTransport {
        private bool $closed = false;

        /** @param list<Queue> $queues */
        public function __construct(
            private readonly array $queues,
            private readonly int $workerId,
            private readonly Queue $myQueue,
            private int &$pushTimeNs,
            private int &$popTimeNs,
            private int &$sleepTimeNs,
            private int &$popCount,
            private int &$emptyPopCount,
        ) {}

        public function send(int $targetWorker, Envelope $envelope): void
        {
            if ($this->closed) {
                return;
            }

            $t = hrtime(true);
            $this->queues[$targetWorker]->push($envelope, 0);
            $this->pushTimeNs += hrtime(true) - $t;
        }

        public function receive(): Envelope
        {
            $emptyCount = 0;

            while (!$this->closed) {
                $t = hrtime(true);
                $envelope = $this->myQueue->pop(0);
                $this->popTimeNs += hrtime(true) - $t;
                $this->popCount++;

                if ($envelope !== null) {
                    return $envelope;
                }

                $this->emptyPopCount++;
                $emptyCount++;

                $sleep = match (true) {
                    $emptyCount < 100 => 0.001,
                    $emptyCount < 1000 => 0.005,
                    default => 0.01,
                };

                $t2 = hrtime(true);
                Coroutine::sleep($sleep);
                $this->sleepTimeNs += hrtime(true) - $t2;
            }

            throw new RuntimeException('closed');
        }

        public function close(): void
        {
            $this->closed = true;
            $this->myQueue->clean();
        }
    };

    // === INSTRUMENTED ROUTER ===
    // Probe: handler callback time (ClusterNode routing)
    $handlerTimeNs = 0;
    $handlerCount = 0;

    $router = new class ($transport, $handlerTimeNs, $handlerCount) implements MessageRouter {
        private bool $closed = false;

        public function __construct(
            private readonly EnvelopeTransport $transport,
            private int &$handlerTimeNs,
            private int &$handlerCount,
        ) {}

        public function send(int $targetWorker, Envelope $envelope): void
        {
            $this->transport->send($targetWorker, $envelope);
        }

        public function startReceiving(callable $handler): void
        {
            while (!$this->closed) {
                try {
                    $envelope = $this->transport->receive();
                    $t = hrtime(true);
                    $handler($envelope);
                    $this->handlerTimeNs += hrtime(true) - $t;
                    $this->handlerCount++;
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

    // === INSTRUMENTED TELL ===
    $envelopeCreateNs = 0;
    $tellCount = 0;

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

    // Probe: actor handler time
    $actorHandlerNs = 0;

    /** @psalm-suppress InvalidArgument */
    $sinkBehavior = Behavior::receive(
        static function (ActorContext $_ctx, object $_msg) use ($delivered, &$actorHandlerNs): Behavior {
            $t = hrtime(true);
            $delivered->add(1);
            $actorHandlerNs += hrtime(true) - $t;

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

    // Probe: send loop — measure envelope creation + tell overhead
    $sendLoopNs = 0;
    $sendSleepNs = 0;
    $localSent = 0;
    $batchSize = 100;

    while ($stopSignal->get() === 0) {
        $t = hrtime(true);

        for ($b = 0; $b < $batchSize; $b++) {
            $tw = $targetWorkerIds[$localSent % $targetCount];
            $ref = $refs[$tw];

            if ($ref !== null) {
                $ref->tell((object) ['s' => $localSent]);
            }

            $localSent++;
        }

        $sendLoopNs += hrtime(true) - $t;
        $sent->add($batchSize);

        $t2 = hrtime(true);
        Coroutine::sleep(0.001);
        $sendSleepNs += hrtime(true) - $t2;
    }

    // Drain
    $drainStart = hrtime(true);

    while ($delivered->get() < $sent->get() && (hrtime(true) - $drainStart) < 5_000_000_000) {
        Coroutine::sleep(0.01);
    }

    // === REPORT RESULTS ===
    if ($workerId === 0) {
        Coroutine::sleep(0.3);
        $results['final_delivered'] = $delivered->get();
        $results['final_sent'] = $sent->get();
    }

    // Store per-worker profiling data
    $results["prof_{$workerId}"] = json_encode([
        'pushNs' => $pushTimeNs,
        'popNs' => $popTimeNs,
        'sleepNs' => $sleepTimeNs,
        'popCount' => $popCount,
        'emptyPops' => $emptyPopCount,
        'handlerNs' => $handlerTimeNs,
        'handlerCount' => $handlerCount,
        'actorNs' => $actorHandlerNs,
        'sendLoopNs' => $sendLoopNs,
        'sendSleepNs' => $sendSleepNs,
        'localSent' => $localSent,
    ]);

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
