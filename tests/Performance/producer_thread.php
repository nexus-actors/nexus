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
// MAX_DEPTH: cap each queue at ~500 K items (~150 MB at 300 B/envelope per queue,
//            ~2.4 GB across 16 queues).  Larger buffer keeps the worker coroutine
//            in its tight drain loop far longer, greatly reducing the 1 ms "queue
//            went empty" sleep overhead.
// PUSH_CHUNK: batch size between depth checks.  Bigger chunks mean fewer
//             count() calls (each involves a mutex) per message.
// push(…, 0): workers use non-blocking pop(0) so no condvar waiter exists;
//             NOTIFY_ONE would acquire+signal+release a mutex for nothing.
const MAX_DEPTH  = 500_000;
const PUSH_CHUNK = 10_000;

$localSent = 0;

while ($stopSignal->get() === 0) {
    // Backpressure: worker is behind — yield CPU so it can drain.
    if ($targetQueue->count() >= MAX_DEPTH) {
        usleep(500); // 0.5 ms — give the worker coroutine its core back

        continue;
    }

    for ($b = 0; $b < PUSH_CHUNK; $b++) {
        $targetQueue->push($envelope, 0); // 0 = no condvar notify; workers use pop(0)
    }

    $localSent += PUSH_CHUNK;
    $statsMap["sent_{$senderId}"] = $localSent;
}
