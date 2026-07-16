<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Thread\ArrayList;
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
 */
final class WorkerRunnable extends Runnable
{
    /**
     * @param array<int, mixed> $args
     */
    #[Override]
    public function run(array $args): void
    {
        if (!$args[0] instanceof Map || !$args[1] instanceof ArrayList || !$args[2] instanceof Atomic) {
            throw new RuntimeException(
                'WorkerRunnable expects thread arguments [Map $directory, ArrayList $queues, Atomic $workerIdCounter, ...scalars]',
            );
        }

        $directory               = $args[0];
        $queues                  = $args[1];
        $workerIdCounter         = $args[2];
        $workerCount             = (int) $args[3];
        $systemNamePrefix        = (string) $args[4];
        $handlerClass            = (string) $args[5];
        $serializedConfigure     = (string) $args[6];
        $loggerClass             = (string) $args[7];
        $serializedLoggerFactory = (string) $args[8];

        $config = WorkerPoolConfig::withThreads($workerCount)->withSystemNamePrefix($systemNamePrefix);

        // Swoole converts PHP arrays to Thread\ArrayList when passing between
        // threads via Pool::withArguments() — convert back to a plain array.
        $queuesArray = [];

        for ($i = 0; $i < $workerCount; $i++) {
            $queuesArray[$i] = self::queueOf($queues[$i]);
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
            $configure = self::closureOf(opis_unserialize($serializedConfigure));
            $configure($node);
        } else {
            self::handlerOf($handlerClass)->onWorkerStart($node);
        }

        // Blocks until $system->shutdown() is called.
        $system->run();
    }

    private static function createLogger(string $loggerClass, string $serializedLoggerFactory): ?LoggerInterface
    {
        if ($loggerClass !== '') {
            if (!is_a($loggerClass, LoggerInterface::class, true)) {
                throw new RuntimeException("Logger class {$loggerClass} must implement " . LoggerInterface::class);
            }

            return new $loggerClass();
        }

        if ($serializedLoggerFactory !== '') {
            $factory = self::closureOf(opis_unserialize($serializedLoggerFactory));

            return self::loggerOf($factory());
        }

        return null;
    }

    private static function handlerOf(string $handlerClass): WorkerStartHandler
    {
        if (!is_a($handlerClass, WorkerStartHandler::class, true)) {
            throw new RuntimeException("Handler class {$handlerClass} must implement " . WorkerStartHandler::class);
        }

        return new $handlerClass();
    }

    private static function queueOf(mixed $value): Queue
    {
        if (!$value instanceof Queue) {
            throw new RuntimeException(
                'Expected Swoole\Thread\Queue in the queues list, got ' . get_debug_type($value),
            );
        }

        return $value;
    }

    private static function closureOf(mixed $value): Closure
    {
        if (!$value instanceof Closure) {
            throw new RuntimeException(
                'Expected a serialized closure, got ' . get_debug_type($value),
            );
        }

        return $value;
    }

    private static function loggerOf(mixed $value): LoggerInterface
    {
        if (!$value instanceof LoggerInterface) {
            throw new RuntimeException(
                'Logger factory must return a ' . LoggerInterface::class . ', got ' . get_debug_type($value),
            );
        }

        return $value;
    }
}
