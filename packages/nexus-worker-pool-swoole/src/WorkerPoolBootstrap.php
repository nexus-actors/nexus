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
 *     // Class-based handler:
 *     WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(8))
 *         ->withHandler(MyApp::class)
 *         ->run();
 *
 *     // WorkerPool DSL (passes serialized closure):
 *     WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(8))
 *         ->withSerializedConfigure($serialized)
 *         ->run();
 */
final class WorkerPoolBootstrap
{
    /** @var class-string<WorkerStartHandler>|null */
    private ?string $handlerClass = null;

    private ?string $serializedConfigure = null;

    private ?string $loggerClass = null;

    private ?string $serializedLoggerFactory = null;

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
        $clone               = clone $this;
        $clone->handlerClass = $handlerClass;

        return $clone;
    }

    /**
     * Serialized opis/closure configure callable. When set, WorkerRunnable
     * runs this instead of the WorkerStartHandler.
     */
    public function withSerializedConfigure(?string $serializedConfigure): self
    {
        $clone                      = clone $this;
        $clone->serializedConfigure = $serializedConfigure;

        return $clone;
    }

    /**
     * @param class-string<\Psr\Log\LoggerInterface>|null $loggerClass
     */
    public function withLoggerClass(?string $loggerClass): self
    {
        $clone              = clone $this;
        $clone->loggerClass = $loggerClass;

        return $clone;
    }

    /**
     * Serialized opis/closure factory: Closure(): LoggerInterface.
     */
    public function withSerializedLoggerFactory(?string $serializedLoggerFactory): self
    {
        $clone                          = clone $this;
        $clone->serializedLoggerFactory = $serializedLoggerFactory;

        return $clone;
    }

    /**
     * Start the worker pool. Blocks until the pool exits.
     */
    public function run(): void
    {
        $directory       = new Map();
        $workerIdCounter = new Atomic(0);

        /** @var array<int, Queue> $queues */
        $queues = [];

        for ($i = 0; $i < $this->config->workerCount; $i++) {
            $queues[$i] = new Queue();
        }

        $handlerClass = $this->handlerClass ?? DefaultWorkerStartHandler::class;

        /** @psalm-suppress UndefinedClass, MissingDependency, MixedAssignment */
        $pool = new Pool(
            WorkerRunnable::class,
            $this->config->workerCount,
            $directory,
            $queues,
            $workerIdCounter,
            $this->config,
            $handlerClass,
            $this->serializedConfigure ?? '',
            $this->loggerClass ?? '',
            $this->serializedLoggerFactory ?? '',
        );

        /** @psalm-suppress MixedMethodCall, UndefinedClass */
        $pool->start();
    }
}
