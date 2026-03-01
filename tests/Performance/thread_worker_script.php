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
 *   3. Boot SwooleRuntime + ActorSystem + WorkerNode
 *   4. Find a sink actor name that hashes to this worker ID (local spawn)
 *   5. Spawn sink actor (increments $messageCounter per message)
 *   6. Publish sink path in $sinkPaths[$workerId]
 *   7. Increment $readyCounter to signal startup complete
 *   8. Poll $stopSignal; when non-zero, shut down actor system and close transport
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
use Swoole\Coroutine;
use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;

use function Swoole\Coroutine\run;

$args = Thread::getArguments();

/** @var string $autoloader */
$autoloader = $args[0];
/** @var Map $directory */
$directory = $args[1];
/** @var array<int, \Swoole\Thread\Queue> $queues */
$queues = $args[2];
/** @var Atomic $workerIdCounter */
$workerIdCounter = $args[3];
/** @var int $workerCount */
$workerCount = $args[4];
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

Coroutine::enableScheduler();

run(static function () use (
    $workerId,
    $directory,
    $queues,
    $workerCount,
    $messageCounter,
    $readyCounter,
    $sinkPaths,
    $stopSignal,
): void {
    $runtime   = new SwooleRuntime();
    $system    = ActorSystem::create("worker-{$workerId}", $runtime);
    $transport = new ThreadQueueTransport($queues, $workerId);

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
        // Extremely unlikely: fall back to bypassing ring routing.
        $sinkName = "sink-fallback-{$workerId}";
    }

    $counter = $messageCounter;

    /** @psalm-suppress InvalidArgument */
    $sinkBehavior = Behavior::receive(
        static function (ActorContext $_ctx, object $_msg) use ($counter): Behavior {
            $counter->add(1);

            return Behavior::same();
        },
    );

    $node->spawn(Props::fromBehavior($sinkBehavior), $sinkName);

    // Publish sink path so the main thread can address this actor directly.
    /** @psalm-suppress InaccessibleProperty, InvalidArgument */
    $sinkPaths[$workerId] = "/user/{$sinkName}";

    // Signal this worker is ready to receive messages.
    $readyCounter->add(1);

    // Poll the stop signal. When set, shut down the actor system and close the
    // transport. Closing the transport ends the receive-loop coroutine (which
    // would otherwise run forever), allowing Co\run() to return.
    $stop = $stopSignal;

    $runtime->scheduleRepeatedly(
        Duration::millis(10),
        Duration::millis(10),
        static function () use ($stop, $system, $transport): void {
            if ($stop->get() === 1) {
                $transport->close();
                $system->shutdown(Duration::millis(200));
            }
        },
    );

    $system->run();
});
