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
 * two scalar properties are passed and the config is rebuilt after autoload.
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
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;

use function Opis\Closure\unserialize as opis_unserialize;

$args = Thread::getArguments();

// Extract scalars and Swoole thread-safe types first — no PHP objects yet.
/** @var string $autoloader */
$autoloader = $args[0];
/** @var Map $directory */
$directory = $args[1];
/** @var \Swoole\Thread\ArrayList $queues */
$queues = $args[2];
/** @var Atomic $workerIdCounter */
$workerIdCounter = $args[3];
/** @var int $workerCount */
$workerCount = (int) $args[4];
/** @var string $systemNamePrefix */
$systemNamePrefix = (string) $args[5];
/** @var string $handlerClass */
$handlerClass = (string) $args[6];
/** @var string $serializedConfigure */
$serializedConfigure = (string) $args[7];
/** @var string $loggerClass */
$loggerClass = (string) $args[8];
/** @var string $serializedLoggerFactory */
$serializedLoggerFactory = (string) $args[9];
/** @var Atomic $readyCounter */
$readyCounter = $args[10];
/** @var Atomic $stopSignal */
$stopSignal = $args[11];

// Load autoloader before using any project classes.
require_once $autoloader;

$workerId = $workerIdCounter->add(1) - 1;

// Reconstruct WorkerPoolConfig from scalars.
$config = WorkerPoolConfig::withThreads($workerCount)->withSystemNamePrefix($systemNamePrefix);

// Swoole converts PHP arrays to Thread\ArrayList when passing between threads — convert back.
$queuesArray = [];

for ($i = 0; $i < $workerCount; $i++) {
    $queuesArray[$i] = $queues[$i];
}

// Create logger.
$logger = null;

if ($loggerClass !== '') {
    /** @psalm-suppress MixedAssignment */
    $logger = new $loggerClass();
} elseif ($serializedLoggerFactory !== '') {
    /** @psalm-suppress MixedFunctionCall */
    $factory = opis_unserialize($serializedLoggerFactory);
    /** @psalm-suppress MixedAssignment */
    $logger = $factory();
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
    /** @psalm-suppress MixedFunctionCall */
    $configure = opis_unserialize($serializedConfigure);
    $configure($node);
} else {
    /** @psalm-suppress MixedMethodCall */
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
