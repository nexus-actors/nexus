<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Swoole\Coroutine;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Swoole\Thread\Runnable;

use function Swoole\Coroutine\run;

/**
 * @psalm-api
 *
 * Thread entrypoint for each worker in the pool.
 * Receives shared Thread\Map (directory) and Thread\Queue[] (inboxes)
 * as constructor arguments — these are the only types that can cross thread boundaries.
 *
 * @psalm-suppress UnusedClass, UndefinedClass, MissingDependency — Swoole\Thread\Pool and Runnable not in stubs
 */
final class WorkerRunnable extends Runnable
{
    /**
     * @param array<int, Queue> $queues
     * @param class-string<WorkerStartHandler> $handlerClass
     */
    public function __construct(
        private readonly Map $directory,
        private readonly array $queues,
        private readonly Atomic $workerIdCounter,
        private readonly WorkerPoolConfig $config,
        private readonly string $handlerClass,
    ) {}

    public function run(): void
    {
        // Atomically claim a worker ID (0-indexed). Each thread gets a unique ID.
        $workerId = $this->workerIdCounter->add(1) - 1;

        Coroutine::enableScheduler();

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use ($workerId): void {
            $runtime = new SwooleRuntime();
            $system = ActorSystem::create("worker-{$workerId}", $runtime);
            $directory = new ThreadMapDirectory($this->directory);
            $transport = new ThreadQueueTransport($this->queues, $workerId);
            $ring = new ConsistentHashRing($this->config->workerCount);
            $node = new WorkerNode(
                $workerId,
                $system,
                $transport,
                $ring,
                $directory,
            );

            $node->start();

            $handler = new $this->handlerClass();
            $handler->onWorkerStart($node);

            $system->run();
        });
    }
}
