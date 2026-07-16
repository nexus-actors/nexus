<?php

/**
 * Standalone thread entry point for WorkerPool with onStart callback.
 *
 * Used when WorkerPool::onStart() is set — raw Swoole\Thread instead of
 * Thread\Pool so the main thread can call the onStart callback while
 * workers are running.
 *
 * WorkerPoolConfig is NOT passed as a PHP object (it would require the class
 * to be loaded before Thread::getArguments() reconstructs it). Instead, its
 * two scalar properties are passed and used directly after autoload.
 *
 * Args via Swoole\Thread::getArguments():
 *   [0]  string    $autoloader
 *   [1]  Map       $directory
 *   [2]  ArrayList $queues
 *   [3]  Atomic    $workerIdCounter
 *   [4]  int       $workerCount
 *   [5]  string    $systemNamePrefix
 *   [6]  string    $handlerClass
 *   [7]  string    $serializedConfigure
 *   [8]  string    $loggerClass
 *   [9]  string    $serializedLoggerFactory
 *   [10] Atomic    $readyCounter
 *   [11] Atomic    $stopSignal
 *
 * IMPORTANT: Do NOT wrap in Swoole\Coroutine\run() — $system->run() starts the
 * event loop itself.
 */

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Psr\Log\LoggerInterface;
use Swoole\Thread;
use Swoole\Thread\ArrayList;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;

use function Opis\Closure\unserialize as opis_unserialize;

$args = Thread::getArguments();

if ($args === null) {
    throw new RuntimeException('worker.php must be started as a Swoole\Thread entry point with thread arguments');
}

// Extract scalars and Swoole thread-safe types first — no PHP objects yet.
if (
    !$args[1] instanceof Map
    || !$args[2] instanceof ArrayList
    || !$args[3] instanceof Atomic
    || !$args[10] instanceof Atomic
    || !$args[11] instanceof Atomic
) {
    throw new RuntimeException(
        'worker.php expects thread arguments [string $autoloader, Map $directory, ArrayList $queues, '
        . 'Atomic $workerIdCounter, ...scalars, Atomic $readyCounter, Atomic $stopSignal]',
    );
}

$autoloader              = (string) $args[0];
$directory               = $args[1];
$queues                  = $args[2];
$workerIdCounter         = $args[3];
$workerCount             = (int) $args[4];
$systemNamePrefix        = (string) $args[5];
$handlerClass            = (string) $args[6];
$serializedConfigure     = (string) $args[7];
$loggerClass             = (string) $args[8];
$serializedLoggerFactory = (string) $args[9];
$readyCounter            = $args[10];
$stopSignal              = $args[11];

// Load autoloader before using any project classes.
if (!is_file($autoloader)) {
    throw new RuntimeException("Autoloader not found: {$autoloader}");
}

require_once $autoloader;

$workerId = $workerIdCounter->add(1) - 1;

// Swoole converts PHP arrays to Thread\ArrayList when passing between threads — convert back.
$queuesArray = [];

for ($i = 0; $i < $workerCount; $i++) {
    $queue = $queues[$i];

    if (!$queue instanceof Queue) {
        throw new RuntimeException(
            'Expected Swoole\Thread\Queue in the queues list, got ' . get_debug_type($queue),
        );
    }

    $queuesArray[$i] = $queue;
}

// Create logger.
$logger = null;

if ($loggerClass !== '') {
    if (!is_a($loggerClass, LoggerInterface::class, true)) {
        throw new RuntimeException("Logger class {$loggerClass} must implement " . LoggerInterface::class);
    }

    $logger = new $loggerClass();
} elseif ($serializedLoggerFactory !== '') {
    $factory = opis_unserialize($serializedLoggerFactory);

    if (!$factory instanceof Closure) {
        throw new RuntimeException('Expected a serialized logger factory closure, got ' . get_debug_type($factory));
    }

    $produced = $factory();

    if (!$produced instanceof LoggerInterface) {
        throw new RuntimeException(
            'Logger factory must return a ' . LoggerInterface::class . ', got ' . get_debug_type($produced),
        );
    }

    $logger = $produced;
}

$runtime    = new SwooleRuntime();
$systemName = "{$systemNamePrefix}-{$workerId}";
$system     = ActorSystem::create($systemName, $runtime, null, $logger);

$threadDirectory = new ThreadMapDirectory($directory);
$transport       = new ThreadQueueTransport($queuesArray, $workerId);
$ring            = new ConsistentHashRing($workerCount);
$node            = new WorkerNode($workerId, $system, $transport, $ring, $threadDirectory);

$node->start();

if ($serializedConfigure !== '') {
    $configure = opis_unserialize($serializedConfigure);

    if (!$configure instanceof Closure) {
        throw new RuntimeException('Expected a serialized configure closure, got ' . get_debug_type($configure));
    }

    $configure($node);
} else {
    if (!is_a($handlerClass, WorkerStartHandler::class, true)) {
        throw new RuntimeException("Handler class {$handlerClass} must implement " . WorkerStartHandler::class);
    }

    (new $handlerClass())->onWorkerStart($node);
}

// Signal this worker is ready for the main thread's onStart callback.
$readyCounter->add(1);

// Poll stop signal — when main thread calls WorkerPoolHandle::stop(),
// close transport and shut down the actor system.
$runtime->scheduleRepeatedly(
    Duration::millis(10),
    Duration::millis(10),
    static function () use ($stopSignal, $system, $transport): void {
        if ($stopSignal->get() === 1) {
            $transport->close();
            $system->shutdown(Duration::millis(200));
        }
    },
);

// Start the Swoole event loop — blocks until $system->shutdown() is called.
$system->run();
