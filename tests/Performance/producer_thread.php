<?php

/**
 * Standalone sender thread for the multi-producer sustained benchmark.
 *
 * Each producer is dedicated to one worker queue (senderId % workerCount),
 * giving a true 1-producer : 1-consumer mapping with zero inter-producer
 * mutex contention on any single queue.
 *
 * Backpressure: the sender spins until the queue drains below MAX_DEPTH
 * before pushing the next chunk.  This keeps queue memory bounded and
 * ensures measured throughput reflects actual worker processing speed,
 * not queue-fill speed.
 *
 * Args via Swoole\Thread::getArguments():
 *   [0] string    $autoloader
 *   [1] ArrayList $queues       (PHP array passed as Swoole ArrayList)
 *   [2] int       $workerCount
 *   [3] int       $senderId
 *   [4] Atomic    $stopSignal
 *   [5] Map       $statsMap     (key "sent_{senderId}" → cumulative count)
 */

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;

$args = Thread::getArguments();

$autoloader  = (string) $args[0];
$queuesArg   = $args[1];
$workerCount = (int) $args[2];
$senderId    = (int) $args[3];
/** @var Atomic $stopSignal */
$stopSignal = $args[4];
/** @var Map $statsMap */
$statsMap = $args[5];

require_once $autoloader;

// Dedicated queue — no contention with other producers.
$targetQueue = $queuesArg[$senderId % $workerCount];

// One pre-allocated envelope per sender (no per-message ULID generation).
$envelope = Envelope::of(
    new stdClass(),
    ActorPath::root(),
    ActorPath::fromString('/user/sink'),
);

// Backpressure constants.
// MAX_DEPTH caps each queue at ~10 K items (~3 MB at 300 B/envelope).
// PUSH_CHUNK is the batch size between depth checks.
const MAX_DEPTH  = 10_000;
const PUSH_CHUNK = 1_000;

$localSent = 0;

while ($stopSignal->get() === 0) {
    // Backpressure: worker is behind — spin until it drains some items.
    while ($targetQueue->count() >= MAX_DEPTH) {
        // intentional busy-wait
    }

    for ($b = 0; $b < PUSH_CHUNK; $b++) {
        $targetQueue->push($envelope, Queue::NOTIFY_ONE);
    }

    $localSent += PUSH_CHUNK;
    $statsMap["sent_{$senderId}"] = $localSent;
}
