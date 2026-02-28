<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole;

use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Pool;
use Swoole\Thread\Queue;

/**
 * @psalm-api
 *
 * Entry point for a Swoole thread-based worker pool.
 *
 * Creates N worker threads, each running an independent ActorSystem.
 * Workers communicate via Thread\Queue (one queue per worker as inbox)
 * and share a Thread\Map as the actor directory.
 *
 * Usage:
 *     WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(8))
 *         ->withHandler(MyWorkerFactory::class)
 *         ->run();
 */
final class WorkerPoolBootstrap
{
    /** @var class-string<WorkerStartHandler>|null */
    private ?string $handlerClass = null;

    private function __construct(private readonly WorkerPoolConfig $config) {}

    public static function create(WorkerPoolConfig $config): self
    {
        return new self($config);
    }

    /**
     * @param class-string<WorkerStartHandler> $handlerClass
     */
    public function withHandler(string $handlerClass): self
    {
        $clone = clone $this;
        $clone->handlerClass = $handlerClass;

        return $clone;
    }

    /**
     * Start the worker pool. Blocks until the pool exits.
     */
    public function run(): void
    {
        $directory = new Map();
        $workerIdCounter = new Atomic(0);

        /** @var array<int, Queue> $queues */
        $queues = [];

        for ($i = 0; $i < $this->config->workerCount; $i++) {
            $queues[$i] = new Queue();
        }

        $handlerClass = $this->handlerClass ?? DefaultWorkerStartHandler::class;

        // Thread\Pool passes the Runnable class name + shared args to every thread.
        // The pool calls run() in each thread automatically — no on('WorkerStart') needed.
        // Worker IDs are claimed atomically since all threads receive the same args.

        /** @psalm-suppress UndefinedClass, MissingDependency, MixedAssignment */
        $pool = new Pool(
            WorkerRunnable::class,
            $this->config->workerCount,
            $directory,
            $queues,
            $workerIdCounter,
            $this->config,
            $handlerClass,
        );

        /** @psalm-suppress MixedMethodCall, UndefinedClass */
        $pool->start();
    }
}
