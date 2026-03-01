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
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Swoole\Thread\Runnable;

use function Opis\Closure\unserialize as opis_unserialize;
use function Swoole\Coroutine\run;

/**
 * @psalm-api
 *
 * Thread entrypoint for each worker in the pool.
 *
 * Receives shared Thread\Map (directory) and Thread\Queue[] (inboxes) plus
 * optional serialized configure closure and logger factory from WorkerPoolBootstrap.
 *
 * @psalm-suppress UnusedClass, UndefinedClass, MissingDependency, UnusedProperty
 */
final class WorkerRunnable extends Runnable
{
    /**
     * @param array<int, Queue>                $queues
     * @param class-string<WorkerStartHandler> $handlerClass
     */
    public function __construct(
        private readonly Map $directory,
        private readonly array $queues,
        private readonly Atomic $workerIdCounter,
        private readonly WorkerPoolConfig $config,
        private readonly string $handlerClass,
        private readonly string $serializedConfigure,
        private readonly string $loggerClass,
        private readonly string $serializedLoggerFactory,
    ) {}

    public function run(): void
    {
        $workerId = $this->workerIdCounter->add(1) - 1;

        Coroutine::enableScheduler();

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use ($workerId): void {
            $logger     = $this->createLogger();
            $runtime    = new SwooleRuntime();
            $systemName = "{$this->config->systemNamePrefix}-{$workerId}";
            $system     = ActorSystem::create($systemName, $runtime, null, $logger);

            $directory = new ThreadMapDirectory($this->directory);
            $transport = new ThreadQueueTransport($this->queues, $workerId);
            $ring      = new ConsistentHashRing($this->config->workerCount);
            $node      = new WorkerNode(
                $workerId,
                $system,
                $transport,
                $ring,
                $directory,
            );

            $node->start();

            if ($this->serializedConfigure !== '') {
                /** @psalm-suppress MixedFunctionCall */
                $configure = opis_unserialize($this->serializedConfigure);
                $configure($node);
            } else {
                $handler = new $this->handlerClass();
                $handler->onWorkerStart($node);
            }

            $system->run();
        });
    }

    /** @psalm-suppress UnusedMethod */
    private function createLogger(): ?LoggerInterface
    {
        if ($this->loggerClass !== '') {
            /** @psalm-suppress MixedReturnStatement, MixedInferredReturnType */
            return new $this->loggerClass();
        }

        if ($this->serializedLoggerFactory !== '') {
            /** @psalm-suppress MixedFunctionCall, MixedReturnStatement, MixedInferredReturnType */
            $factory = opis_unserialize($this->serializedLoggerFactory);

            return $factory();
        }

        return null;
    }
}
