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
use Psr\Log\LoggerInterface;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Swoole\Thread\Runnable;

use function Opis\Closure\unserialize as opis_unserialize;

/**
 * @psalm-api
 *
 * Thread entrypoint for each worker in the pool.
 *
 * Swoole\Thread\Pool constructs each Runnable with exactly
 * (Atomic $running, int $index) and then invokes run($args), where $args is
 * the array provided via Pool::withArguments(). All worker dependencies must
 * therefore travel through $args — they cannot be injected via the constructor.
 *
 * Argument layout (matches WorkerPoolBootstrap::runWithPool()):
 *   [0] Map       $directory
 *   [1] ArrayList $queues             — Swoole converts PHP array → ArrayList
 *   [2] Atomic    $workerIdCounter
 *   [3] int       $workerCount        — scalars used to rebuild WorkerPoolConfig
 *   [4] string    $systemNamePrefix
 *   [5] string    $handlerClass
 *   [6] string    $serializedConfigure
 *   [7] string    $loggerClass
 *   [8] string    $serializedLoggerFactory
 *
 * The parent Runnable defines $running and $id; we inherit its constructor
 * unchanged and do all real work inside run().
 *
 * IMPORTANT: Do NOT wrap the body in Swoole\Coroutine\run() — $system->run()
 * starts the Swoole event loop itself, and nesting another run() inside a
 * coroutine raises "Unable to call Event::wait() in coroutine".
 *
 * @psalm-suppress UnusedClass, UndefinedClass, MissingDependency, PropertyNotSetInConstructor
 */
final class WorkerRunnable extends Runnable
{
    /**
     * @param array<int, mixed> $args
     *
     * @psalm-suppress UnusedParam
     */
    public function run(array $args): void
    {
        /** @var Map $directory */
        $directory = $args[0];
        /** @var \Swoole\Thread\ArrayList $queues */
        $queues = $args[1];
        /** @var Atomic $workerIdCounter */
        $workerIdCounter = $args[2];
        /** @var int $workerCount */
        $workerCount = (int) $args[3];
        /** @var string $systemNamePrefix */
        $systemNamePrefix = (string) $args[4];
        /** @var string $handlerClass */
        $handlerClass = (string) $args[5];
        /** @var string $serializedConfigure */
        $serializedConfigure = (string) $args[6];
        /** @var string $loggerClass */
        $loggerClass = (string) $args[7];
        /** @var string $serializedLoggerFactory */
        $serializedLoggerFactory = (string) $args[8];

        $config = WorkerPoolConfig::withThreads($workerCount)->withSystemNamePrefix($systemNamePrefix);

        // Swoole converts PHP arrays to Thread\ArrayList when passing between
        // threads via Pool::withArguments() — convert back to a plain array.
        /** @var array<int, Queue> $queuesArray */
        $queuesArray = [];

        for ($i = 0; $i < $workerCount; $i++) {
            /** @psalm-suppress MixedAssignment, MixedArrayAccess */
            $queuesArray[$i] = $queues[$i];
        }

        $workerId   = $workerIdCounter->add(1) - 1;
        $logger     = self::createLogger($loggerClass, $serializedLoggerFactory);
        $runtime    = new SwooleRuntime();
        $systemName = "{$config->systemNamePrefix}-{$workerId}";
        $system     = ActorSystem::create($systemName, $runtime, null, $logger);

        $directoryAdapter = new ThreadMapDirectory($directory);
        $transport        = new ThreadQueueTransport($queuesArray, $workerId);
        $ring             = new ConsistentHashRing($config->workerCount);
        $node             = new WorkerNode($workerId, $system, $transport, $ring, $directoryAdapter);

        $node->start();

        if ($serializedConfigure !== '') {
            /** @psalm-suppress MixedFunctionCall */
            $configure = opis_unserialize($serializedConfigure);
            $configure($node);
        } else {
            /** @psalm-suppress MixedMethodCall */
            $handler = new $handlerClass();
            /** @psalm-suppress MixedMethodCall */
            $handler->onWorkerStart($node);
        }

        // Blocks until $system->shutdown() is called.
        $system->run();
    }

    /** @psalm-suppress UnusedMethod */
    private static function createLogger(string $loggerClass, string $serializedLoggerFactory): ?LoggerInterface
    {
        if ($loggerClass !== '') {
            /** @psalm-suppress MixedReturnStatement, MixedInferredReturnType, MixedMethodCall */
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
