<?php

/**
 * Standalone sender thread for the multi-producer sustained benchmark.
 *
 * Each producer is dedicated to one worker queue (senderId % workerCount),
 * giving a true 1-producer : 1-consumer mapping with zero inter-producer
 * mutex contention on any single queue.
 *
 * Args via Swoole\Thread::getArguments():
 *   [0] string    $autoloader
 *   [1] ArrayList $queues       (PHP array passed as Swoole ArrayList)
 *   [2] int       $workerCount
 *   [3] int       $senderId
 *   [4] int       $batchSize
 *   [5] Atomic    $stopSignal
 *   [6] Map       $statsMap     (key "sent_{senderId}" → cumulative count)
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
$batchSize   = (int) $args[4];
/** @var Atomic $stopSignal */
$stopSignal = $args[5];
/** @var Map $statsMap */
$statsMap = $args[6];

require_once $autoloader;

// Dedicated queue — no contention with other producers.
$targetQueue = $queuesArg[$senderId % $workerCount];

// One pre-allocated envelope per sender (no per-message ULID generation).
$envelope = Envelope::of(
    new stdClass(),
    ActorPath::root(),
    ActorPath::fromString('/user/sink'),
);

$localSent = 0;

while ($stopSignal->get() === 0) {
    for ($b = 0; $b < $batchSize; $b++) {
        $targetQueue->push($envelope, Queue::NOTIFY_ONE);
    }

    $localSent += $batchSize;
    $statsMap["sent_{$senderId}"] = $localSent;
}
