<?php

/**
 * Standalone thread entry point for WorkerPool throughput performance tests.
 *
 * This script runs inside each Swoole\Thread worker.
 * Arguments received via Swoole\Thread::getArguments():
 *   [0] string  $autoloader     — path to vendor/autoload.php
 *   [1] Map     $directory      — shared actor directory (Thread\Map)
 *   [2] array   $queues         — per-worker inboxes (array<int, Queue>)
 *   [3] Atomic  $workerIdCounter — monotonic ID counter; each thread claims unique ID
 *   [4] int     $workerCount    — total number of worker threads
 *   [5] Atomic  $messageCounter — incremented per message received by sink actor
 *   [6] Atomic  $readyCounter   — incremented once per worker when startup complete
 *   [7] Map     $sinkPaths      — sink actor path per worker; published on startup
 *   [8] Atomic  $stopSignal     — set to 1 by main thread to request graceful stop
 *
 * Lifecycle per thread:
 *   1. Load autoloader so all project classes are available
 *   2. Atomically claim a unique worker ID
 *   3. Boot SwooleRuntime + ActorSystem + WorkerNode (all synchronous setup)
 *   4. Find a sink actor name that hashes to this worker ID (local spawn)
 *   5. Spawn sink actor (increments $messageCounter per message)
 *   6. Publish sink path in $sinkPaths[$workerId]
 *   7. Increment $readyCounter to signal startup complete
 *   8. Register stop-signal poll timer
 *   9. $system->run() — starts Swoole event loop (blocks until shutdown)
 *
 * IMPORTANT: Do NOT wrap in Swoole\Coroutine\run() — $system->run() starts the
 * event loop itself. Nesting run() inside run() causes "Unable to call Event::wait()
 * in coroutine" fatal error.
 */

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;

$args = Thread::getArguments();

/** @var string $autoloader */
$autoloader = $args[0];
/** @var Map $directory */
$directory = $args[1];
/** @var \Swoole\Thread\ArrayList $queues — Swoole converts PHP arrays to ArrayList in threads */
$queues = $args[2];
/** @var Atomic $workerIdCounter */
$workerIdCounter = $args[3];
/** @var int $workerCount */
$workerCount = (int) $args[4];
/** @var Atomic $messageCounter */
$messageCounter = $args[5];
/** @var Atomic $readyCounter */
$readyCounter = $args[6];
/** @var Map $sinkPaths */
$sinkPaths = $args[7];
/** @var Atomic $stopSignal */
$stopSignal = $args[8];

require_once $autoloader;

$workerId = $workerIdCounter->add(1) - 1;

// Swoole converts PHP arrays to Thread\ArrayList when passing between threads — convert back.
$queuesArray = [];

for ($i = 0; $i < $workerCount; $i++) {
    $queuesArray[$i] = $queues[$i];
}

// All setup is synchronous — no coroutine context needed yet.
// SwooleRuntime queues spawn/schedule calls until $system->run() starts the event loop.
$runtime   = new SwooleRuntime();
$system    = ActorSystem::create("worker-{$workerId}", $runtime);
$transport = new ThreadQueueTransport($queuesArray, $workerId);

$threadDirectory = new ThreadMapDirectory($directory);
$ring            = new ConsistentHashRing($workerCount);
$node            = new WorkerNode($workerId, $system, $transport, $ring, $threadDirectory);

$node->start();

// Find an actor name that hashes to this worker so the spawn is guaranteed local.
$sinkName = null;

for ($i = 0; $i < 100_000; $i++) {
    $candidate = "perf-sink-{$workerId}-{$i}";

    if ($ring->getWorker($candidate) === $workerId) {
        $sinkName = $candidate;

        break;
    }
}

if ($sinkName === null) {
    $sinkName = "sink-fallback-{$workerId}";
}

$counter = $messageCounter;

$sinkBehavior = Behavior::receive(
    static function (ActorContext $_ctx, object $_msg) use ($counter): Behavior {
        $counter->add(1);

        return Behavior::same();
    },
);

$node->spawn(Props::fromBehavior($sinkBehavior), $sinkName);

// Publish sink path so the main thread can address this actor directly.
$sinkPaths[$workerId] = "/user/{$sinkName}";

// Signal startup complete — messages pushed to the queue before the event loop
// starts will be drained by the listener coroutine once $system->run() begins.
$readyCounter->add(1);

// Register stop-signal poll timer. When stopSignal=1, close transport (ends the
// receive-loop coroutine) then shutdown the actor system, allowing run() to return.
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
