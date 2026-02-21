#!/usr/bin/env php
<?php

/**
 * Timed Thread Cluster Performance Benchmark
 *
 * Spawns N real OS threads, all sending messages continuously for a fixed duration.
 * Reports throughput snapshots every 10 seconds and final results.
 *
 * Usage:
 *   docker compose exec php-swoole-thread php tests/Performance/thread_cluster_benchmark_timed.php [workers] [seconds]
 *
 * Examples:
 *   php tests/Performance/thread_cluster_benchmark_timed.php 16 300   # 16 threads, 5 minutes
 *   php tests/Performance/thread_cluster_benchmark_timed.php 8 60     # 8 threads, 1 minute
 *
 * @psalm-suppress UndefinedClass, MixedMethodCall, MixedAssignment
 */

declare(strict_types=1);

use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Atomic\Long;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;

require __DIR__ . '/../../vendor/autoload.php';

$workerCount = (int) ($argv[1] ?? 16);
$durationSec = (int) ($argv[2] ?? 300);
$workerScript = __DIR__ . '/thread_cluster_worker_timed.php';

echo "\n";
echo "=== Thread Cluster Sustained Load Benchmark ===\n";
echo "\n";
echo "  Workers:            {$workerCount}\n";
echo "  Duration:           {$durationSec}s (" . gmdate('i\ms\s', $durationSec) . ")\n";
echo "  Transport:          Thread\\Queue (adaptive polling)\n";
echo "  Directory:          Thread\\Map\n";
echo "\n";

// Shared state
$map = new Map();
$queues = new Map();

for ($i = 0; $i < $workerCount; $i++) {
    $queues[$i] = new Queue();
}

// Use Long for 64-bit counters (Atomic is 32-bit on some platforms)
$delivered = new Long(0);
$sent = new Long(0);
$ready = new Atomic(0);
$startSignal = new Atomic(0);
$stopSignal = new Atomic(0);
$results = new Map();

echo "  Spawning {$workerCount} threads...\n";

// Spawn threads
$threads = [];

for ($i = 0; $i < $workerCount; $i++) {
    $threads[$i] = new Thread(
        $workerScript,
        $i,
        $workerCount,
        $map,
        $queues,
        $delivered,
        $sent,
        $ready,
        $startSignal,
        $stopSignal,
        $results,
    );
}

echo "  Waiting for all workers to be ready...\n";

while ($ready->get() < $workerCount) {
    usleep(1000);
}

echo "  All {$workerCount} workers ready.\n";
echo "\n";
echo "  GO! Running for {$durationSec}s...\n";
echo "\n";

// Start benchmark
$startNs = hrtime(true);
$startSignal->set(1);

// Report progress every 10 seconds
$reportInterval = 10;
$nextReport = $reportInterval;
$prevDelivered = 0;
$prevTime = $startNs;

echo str_pad('Time', 8) . str_pad('Sent', 14) . str_pad('Delivered', 14) . str_pad('Throughput', 16) . str_pad(
    'Instant',
    16,
) . "\n";
echo str_repeat('-', 68) . "\n";

$elapsed = 0;

while ($elapsed < $durationSec) {
    usleep(1_000_000); // 1 second
    $now = hrtime(true);
    $elapsed = (int) (($now - $startNs) / 1_000_000_000);

    if ($elapsed >= $nextReport || $elapsed >= $durationSec) {
        $currentSent = $sent->get();
        $currentDelivered = $delivered->get();

        $avgThroughput = $elapsed > 0
            ? $currentDelivered / $elapsed
            : 0;

        $intervalNs = $now - $prevTime;
        $intervalDelivered = $currentDelivered - $prevDelivered;
        $instantThroughput = $intervalNs > 0
            ? $intervalDelivered / ($intervalNs / 1_000_000_000)
            : 0;

        printf(
            "  %-6ss %-13s %-13s %-15s %-15s\n",
            $elapsed,
            number_format($currentSent),
            number_format($currentDelivered),
            number_format($avgThroughput, 0) . ' avg/s',
            number_format($instantThroughput, 0) . ' cur/s',
        );

        $prevDelivered = $currentDelivered;
        $prevTime = $now;
        $nextReport = $elapsed + $reportInterval;
    }
}

// Signal stop
$stopSignal->set(1);

echo "\n  Stopping... waiting for drain and thread cleanup...\n";

// Wait for all threads to finish
foreach ($threads as $thread) {
    $thread->join();
}

$endNs = hrtime(true);
$totalWallMs = ($endNs - $startNs) / 1_000_000;

// Final results
$finalDelivered = $results['final_delivered'] ?? $delivered->get();
$finalSent = $results['final_sent'] ?? $sent->get();

$avgThroughput = $totalWallMs > 0
    ? $finalDelivered / $totalWallMs * 1000
    : 0;

echo "\n";
echo "=== Final Results ===\n";
echo "\n";
echo "  Total sent:         " . number_format((int) $finalSent) . "\n";
echo "  Total delivered:    " . number_format((int) $finalDelivered) . "\n";
echo "  Loss:               " . number_format(max(0, (int) $finalSent - (int) $finalDelivered)) . "\n";
echo "  Wall clock:         " . number_format($totalWallMs / 1000, 1) . "s\n";
echo "  Avg throughput:     " . number_format($avgThroughput, 0) . " msgs/sec\n";
echo "  Per-worker avg:     " . number_format($avgThroughput / max($workerCount, 1), 0) . " msgs/sec/worker\n";
echo "\n";

$loss = max(0, (int) $finalSent - (int) $finalDelivered);

if ($loss > 0) {
    $lossRate = $finalSent > 0
        ? $loss / $finalSent * 100
        : 0;
    echo "  WARNING: {$loss} messages lost ({$lossRate}%)\n\n";
} else {
    echo "  Status: ZERO LOSS\n\n";
}
