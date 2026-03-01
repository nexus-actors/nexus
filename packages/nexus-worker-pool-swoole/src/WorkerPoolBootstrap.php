<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole;

use Closure;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use RuntimeException;
use Swoole\Thread;
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

    /** @var (Closure(WorkerPoolHandle): void)|null */
    private ?Closure $onStart = null;

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
     * Register a main-thread callback to run after all workers are ready.
     * When set, raw Swoole\Thread instances are used instead of Thread\Pool
     * so the main thread can invoke the callback while workers are running.
     *
     * @param (Closure(WorkerPoolHandle): void)|null $onStart
     */
    public function withOnStart(?Closure $onStart): self
    {
        $clone          = clone $this;
        $clone->onStart = $onStart;

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
        $onStart      = $this->onStart;

        if ($onStart !== null) {
            $this->runWithOnStart($directory, $queues, $workerIdCounter, $handlerClass, $onStart);
        } else {
            $this->runWithPool($directory, $queues, $workerIdCounter, $handlerClass);
        }
    }

    /**
     * @param array<int, Queue>                $queues
     * @param class-string<WorkerStartHandler> $handlerClass
     */
    private function runWithPool(Map $directory, array $queues, Atomic $workerIdCounter, string $handlerClass): void
    {
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

    /**
     * @param array<int, Queue>                $queues
     * @param class-string<WorkerStartHandler> $handlerClass
     * @param Closure(WorkerPoolHandle): void  $onStart
     */
    private function runWithOnStart(
        Map $directory,
        array $queues,
        Atomic $workerIdCounter,
        string $handlerClass,
        Closure $onStart,
    ): void {
        $workerScript = __DIR__ . '/../bin/worker.php';
        $autoloader   = $this->findAutoloader();
        $readyCounter = new Atomic(0);
        $stopSignal   = new Atomic(0);

        /** @var list<\Swoole\Thread> $threads */
        $threads = [];

        for ($i = 0; $i < $this->config->workerCount; $i++) {
            /** @psalm-suppress UndefinedClass */
            $threads[] = new Thread(
                $workerScript,
                $autoloader,
                $directory,
                $queues,
                $workerIdCounter,
                $this->config->workerCount,
                $this->config->systemNamePrefix,
                $handlerClass,
                $this->serializedConfigure ?? '',
                $this->loggerClass ?? '',
                $this->serializedLoggerFactory ?? '',
                $readyCounter,
                $stopSignal,
            );
        }

        // Wait for all workers to become ready.
        $deadline = time() + 30;

        while ($readyCounter->get() < $this->config->workerCount) {
            if (time() > $deadline) {
                $stopSignal->set(1);

                foreach ($threads as $thread) {
                    /** @psalm-suppress UndefinedClass */
                    $thread->join();
                }

                throw new RuntimeException('Worker threads did not become ready within 30 seconds');
            }

            usleep(10_000);
        }

        // Build handle and call onStart.
        $handle = new WorkerPoolHandle($this->config->workerCount, $queues, $directory, $stopSignal);

        $onStart($handle);

        // Ensure stop is signalled after onStart returns.
        $stopSignal->set(1);

        foreach ($threads as $thread) {
            /** @psalm-suppress UndefinedClass */
            $thread->join();
        }
    }

    private function findAutoloader(): string
    {
        foreach (get_included_files() as $file) {
            if (str_ends_with($file, 'vendor/autoload.php')) {
                return $file;
            }
        }

        throw new RuntimeException('Cannot locate vendor/autoload.php in included files');
    }
}
