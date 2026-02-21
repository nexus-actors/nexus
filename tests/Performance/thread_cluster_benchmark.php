#!/usr/bin/env php
<?php

/**
 * Thread Cluster Performance Benchmark
 *
 * Spawns N real OS threads via Swoole\Thread, each running an independent
 * ActorSystem with ThreadQueueTransport. All workers send messages to actors
 * on other workers simultaneously — full cross-worker load.
 *
 * Usage:
 *   docker compose exec php-swoole-thread php tests/Performance/thread_cluster_benchmark.php [workers] [msgs_per_worker]
 *
 * Examples:
 *   php tests/Performance/thread_cluster_benchmark.php 16 10000   # 16 threads, 10K msgs each
 *   php tests/Performance/thread_cluster_benchmark.php 4 50000    # 4 threads, 50K msgs each
 *
 * @psalm-suppress UndefinedClass, MixedMethodCall, MixedAssignment
 */

declare(strict_types=1);

use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;

require __DIR__ . '/../../vendor/autoload.php';

$workerCount = (int) ($argv[1] ?? 16);
$messagesPerWorker = (int) ($argv[2] ?? 10_000);
$totalMessages = $messagesPerWorker * $workerCount;
$workerScript = __DIR__ . '/thread_cluster_worker.php';

echo "\n";
echo "=== Thread Cluster Performance Benchmark ===\n";
echo "\n";
echo "  Workers:            {$workerCount}\n";
echo "  Messages/worker:    " . number_format($messagesPerWorker) . "\n";
echo "  Total messages:     " . number_format($totalMessages) . "\n";
echo "  Transport:          Thread\\Queue (adaptive polling)\n";
echo "  Directory:          Thread\\Map\n";
echo "\n";

// Shared state — all thread-safe objects
$map = new Map();

// Use Map<int, Queue> for queues (PHP arrays can't cross threads)
$queues = new Map();

for ($i = 0; $i < $workerCount; $i++) {
    $queues[$i] = new Queue();
}

// Coordination atomics
$delivered = new Atomic(0);
$ready = new Atomic(0);
$startSignal = new Atomic(0);
$results = new Map();

echo "  Spawning {$workerCount} threads...\n";

// Spawn worker threads
$threads = [];

for ($i = 0; $i < $workerCount; $i++) {
    $threads[$i] = new Thread(
        $workerScript,
        $i,               // workerId
        $workerCount,     // workerCount
        $messagesPerWorker,
        $totalMessages,
        $map,             // shared directory
        $queues,          // shared queue map
        $delivered,       // shared delivery counter
        $ready,           // ready counter
        $startSignal,     // start signal
        $results,         // results map
    );
}

echo "  Waiting for all workers to be ready...\n";

// Wait for all workers to signal ready
while ($ready->get() < $workerCount) {
    usleep(1000);
}

echo "  All workers ready. Starting benchmark...\n";

// Fire! Record start time and signal all workers
$startNs = hrtime(true);
$startSignal->set(1);

// Wait for all threads to finish
foreach ($threads as $thread) {
    $thread->join();
}

$endNs = hrtime(true);
$elapsedMs = ($endNs - $startNs) / 1_000_000;

// Read results
$actualDelivered = $results['delivered'] ?? $delivered->get();

$throughput = $elapsedMs > 0
    ? $actualDelivered / $elapsedMs * 1000
    : 0;

echo "\n";
echo "=== Results ===\n";
echo "\n";
echo "  Delivery:           " . number_format((int) $actualDelivered) . " / " . number_format(
    $totalMessages,
) . " messages\n";
echo "  Wall clock:         " . number_format($elapsedMs, 1) . " ms\n";
echo "  E2E throughput:     " . number_format($throughput, 0) . " msgs/sec\n";
echo "  Per-worker avg:     " . number_format($throughput / max($workerCount, 1), 0) . " msgs/sec/worker\n";
echo "\n";

if ($actualDelivered < $totalMessages) {
    echo "  WARNING: Not all messages delivered! ({$actualDelivered}/{$totalMessages})\n\n";
    exit(1);
}

echo "  Status: ALL DELIVERED\n\n";
