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
 * In Swoole 6.2+, Thread\Pool generates thread_runner.php which calls:
 *   new WorkerRunnable($running, $index)
 *   $runnable->run($extraArgs)
 *
 * where $extraArgs are the arguments beyond (class, count) passed to new Pool().
 *
 * @psalm-suppress UnusedClass, UndefinedClass, MissingDependency, UnusedMethod, UnusedProperty
 */
final class WorkerRunnable extends Runnable
{
    /**
     * Swoole 6.2+ Thread\Pool passes (Atomic $running, int $index) to the constructor.
     * $index is the 0-based thread index used as the worker ID.
     */
    public function __construct(private readonly Atomic $running, private readonly int $index) {}

    /**
     * Called by Swoole with the extra args from new Pool(class, count, ...$args).
     *
     * @psalm-suppress UnusedParam
     *
     * @param array{
     *     0: Map,
     *     1: array<int, Queue>,
     *     2: WorkerPoolConfig,
     *     3: class-string<WorkerStartHandler>,
     *     4: string,
     *     5: string,
     *     6: string,
     * } $args
     */
    public function run(array $args = []): void
    {
        /** @var Map $directory */
        $directory = $args[0];
        /** @var array<int, Queue> $queues */
        $queues = $args[1];
        /** @var WorkerPoolConfig $config */
        $config = $args[2];
        /** @var class-string<WorkerStartHandler> $handlerClass */
        $handlerClass = $args[3];
        $serializedConfigure = (string) ($args[4] ?? '');
        $loggerClass = (string) ($args[5] ?? '');
        $serializedLoggerFactory = (string) ($args[6] ?? '');

        $workerId = $this->index;
        $logger   = $this->createLogger($loggerClass, $serializedLoggerFactory);

        Coroutine::enableScheduler();

        /** @psalm-suppress UnusedFunctionCall */
        run(
            static function () use ($workerId, $directory, $queues, $config, $handlerClass, $serializedConfigure, $logger): void {
                $runtime    = new SwooleRuntime();
                $systemName = "{$config->systemNamePrefix}-{$workerId}";
                $system     = ActorSystem::create($systemName, $runtime, null, $logger);

                $dir       = new ThreadMapDirectory($directory);
                $transport = new ThreadQueueTransport($queues, $workerId);
                $ring      = new ConsistentHashRing($config->workerCount);
                $node      = new WorkerNode(
                    $workerId,
                    $system,
                    $transport,
                    $ring,
                    $dir,
                );

                $node->start();

                if ($serializedConfigure !== '') {
                    /** @psalm-suppress MixedFunctionCall */
                    $configure = opis_unserialize($serializedConfigure);
                    $configure($node);
                } else {
                    $handler = new $handlerClass();
                    $handler->onWorkerStart($node);
                }

                $system->run();
            },
        );
    }

    /** @psalm-suppress UnusedMethod */
    private function createLogger(string $loggerClass, string $serializedLoggerFactory): ?LoggerInterface
    {
        if ($loggerClass !== '') {
            /** @psalm-suppress MixedReturnStatement, MixedInferredReturnType */
            return new $loggerClass();
        }

        if ($serializedLoggerFactory !== '') {
            /** @psalm-suppress MixedFunctionCall, MixedReturnStatement, MixedInferredReturnType */
            $factory = opis_unserialize($serializedLoggerFactory);

            return $factory();
        }

        return null;
    }
}
