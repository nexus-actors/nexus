<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\SwooleThread;

use Closure;
use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\DirectRemoteActorRef;
use Monadial\Nexus\Cluster\Router\DirectRouter;
use Monadial\Nexus\Cluster\SwooleThread\Directory\ThreadMapDirectory;
use Monadial\Nexus\Cluster\SwooleThread\Transport\ThreadQueueTransport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Coroutine;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Swoole\Thread\Runnable;

use function Swoole\Coroutine\run;

/**
 * @psalm-api
 * @psalm-suppress UndefinedClass
 *
 * Worker thread entry point. Runs inside Thread\Pool.
 * Each instance receives shared Thread\Queue[] and Thread\Map via arguments,
 * creates its own ActorSystem, and processes messages via adaptive polling.
 *
 * Swoole Thread classes require PHP ZTS and are not covered by swoole/ide-helper stubs.
 */
final class ThreadWorker extends Runnable
{
    /**
     * @param array{callback: ?Closure(ClusterNode): void, map: Map, queues: list<Queue>, workerCount: int} $args
     *
     * @psalm-suppress UnusedParam
     */
    public function run(array $args): void
    {
        /** @var list<Queue> $queues */
        $queues = $args['queues'];
        /** @var Map $map */
        $map = $args['map'];
        /** @var int $workerCount */
        $workerCount = $args['workerCount'];
        /** @var ?Closure(ClusterNode): void $callback */
        $callback = $args['callback'] ?? null;

        $workerId = $this->id;

        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use ($workerId, $queues, $map, $workerCount, $callback): void {
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

            // Start receive loop in a dedicated coroutine
            Coroutine::create(static function () use ($node): void {
                $node->start();
            });

            if ($callback !== null) {
                $callback($node);
            }

            $system->run();
        });
    }
}
