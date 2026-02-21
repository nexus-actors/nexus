<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance;

use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\DirectRemoteActorRef;
use Monadial\Nexus\Cluster\Router\DirectRouter;
use Monadial\Nexus\Cluster\SwooleThread\Directory\ThreadMapDirectory;
use Monadial\Nexus\Cluster\SwooleThread\Transport\ThreadQueueTransport;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Swoole\Thread\Runnable;

use function Swoole\Coroutine\run;

/**
 * @psalm-suppress UndefinedClass, UndefinedDocblockClass, MixedMethodCall, MixedAssignment
 */
final class ThreadClusterBenchmarkWorker extends Runnable
{
    /**
     * @param array{
     *     map: Map,
     *     queues: list<Queue>,
     *     workerCount: int,
     *     messagesPerWorker: int,
     *     totalMessages: int,
     *     delivered: Atomic,
     *     ready: Atomic,
     *     results: Map,
     * } $args
     */
    public function run(array $args): void
    {
        /** @var list<Queue> $queues */
        $queues = $args['queues'];
        /** @var Map $map */
        $map = $args['map'];
        /** @var int $workerCount */
        $workerCount = $args['workerCount'];
        /** @var int $messagesPerWorker */
        $messagesPerWorker = $args['messagesPerWorker'];
        /** @var int $totalMessages */
        $totalMessages = $args['totalMessages'];
        /** @var Atomic $delivered */
        $delivered = $args['delivered'];
        /** @var Atomic $ready */
        $ready = $args['ready'];
        /** @var Map $results */
        $results = $args['results'];

        $workerId = $this->id;

        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use (
            $workerId,
            $queues,
            $map,
            $workerCount,
            $messagesPerWorker,
            $totalMessages,
            $delivered,
            $ready,
            $results,
        ): void {
            $runtime = new SwooleRuntime();
            $system = ActorSystem::create("worker-{$workerId}", $runtime);

            $directory = new ThreadMapDirectory($map);
            $ring = new ConsistentHashRing($workerCount);
            $transport = new ThreadQueueTransport($queues, $workerId);
            $router = new DirectRouter($transport);

            $remoteRefFactory = static fn(ActorPath $path, int $targetWorker) => new DirectRemoteActorRef(
                $path,
                $targetWorker,
                $transport,
                $directory,
            );

            $node = new ClusterNode(
                $workerId,
                $system,
                $router,
                $ring,
                $directory,
                $remoteRefFactory,
            );

            // Start receive loop in dedicated coroutine
            Coroutine::create(static function () use ($node): void {
                $node->start();
            });

            // Find actor name for this worker and spawn sink actor
            $myName = self::findNameForWorker($ring, $workerId);

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
                    $targetNames[$w] = self::findNameForWorker($ring, $w);
                }
            }

            $targetWorkerIds = array_keys($targetNames);

            // Benchmark: send messagesPerWorker messages to other workers
            $sendStart = hrtime(true);

            for ($i = 0; $i < $messagesPerWorker; $i++) {
                $targetWorker = $targetWorkerIds[$i % count($targetWorkerIds)];
                $ref = $node->actorFor("/user/{$targetNames[$targetWorker]}");

                if ($ref !== null) {
                    $ref->tell((object) ['seq' => $i, 'from' => $workerId]);
                }
            }

            $sendEnd = hrtime(true);

            // Worker 0 waits for all messages to be delivered and reports results
            if ($workerId === 0) {
                $maxWait = 60.0;
                $waited = 0.0;

                while ($delivered->get() < $totalMessages && $waited < $maxWait) {
                    Coroutine::sleep(0.01);
                    $waited += 0.01;
                }

                $endTime = hrtime(true);
                $results['elapsed_ms'] = (int) (($endTime - $sendStart) / 1_000_000);
                $results['send_ms'] = (int) (($sendEnd - $sendStart) / 1_000_000);
                $results['delivered'] = $delivered->get();
                $results['total_expected'] = $totalMessages;
            } else {
                // Other workers wait for worker 0 to finish recording
                while (!isset($results['delivered'])) {
                    Coroutine::sleep(0.05);
                }
            }

            // Brief delay to ensure results are recorded
            Coroutine::sleep(0.2);

            // Cleanup
            $router->close();
            $system->shutdown(Duration::millis(500));
        });
    }

    private static function findNameForWorker(ConsistentHashRing $ring, int $workerId): string
    {
        for ($i = 0; $i < 100_000; $i++) {
            $name = "actor-{$i}";

            if ($ring->getWorker($name) === $workerId) {
                return $name;
            }
        }

        throw new RuntimeException("Could not find a name that hashes to worker {$workerId}");
    }
}
