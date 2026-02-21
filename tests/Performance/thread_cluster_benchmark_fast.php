#!/usr/bin/env php
<?php

/**
 * Fast-yield Thread Cluster benchmark.
 * Uses unbuffered Channel (~0.3μs yield) instead of Coroutine::sleep(0.001) (~1.4ms).
 *
 * Usage:
 *   docker compose exec php-swoole-thread php tests/Performance/thread_cluster_benchmark_fast.php [workers] [seconds] [batch]
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
$durationSec = (int) ($argv[2] ?? 30);
$batchSize = (int) ($argv[3] ?? 100);
$workerScript = __DIR__ . '/thread_cluster_worker_nosleep.php';

echo "\n";
echo "=== Fast-Yield Thread Cluster Benchmark ===\n";
echo "\n";
echo "  Workers:    {$workerCount}\n";
echo "  Duration:   {$durationSec}s\n";
echo "  Batch size: {$batchSize}\n";
echo "  Yield:      Channel (~0.3μs)\n";
echo "\n";

$map = new Map();
$queues = new Map();

for ($i = 0; $i < $workerCount; $i++) {
    $queues[$i] = new Queue();
}

$delivered = new Long(0);
$sent = new Long(0);
$ready = new Atomic(0);
$startSignal = new Atomic(0);
$stopSignal = new Atomic(0);
$results = new Map();

echo "  Spawning {$workerCount} threads...\n";

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
        $batchSize,
    );
}

while ($ready->get() < $workerCount) {
    usleep(1000);
}

echo "  All ready. GO!\n\n";

$startNs = hrtime(true);
$startSignal->set(1);

$prevDelivered = 0;
$prevTime = $startNs;

echo str_pad('Time', 8) . str_pad('Delivered', 16) . str_pad('Avg/s', 16) . str_pad('Instant/s', 16) . "\n";
echo str_repeat('-', 56) . "\n";

$elapsed = 0;
$nextReport = 5;

while ($elapsed < $durationSec) {
    usleep(1_000_000);
    $now = hrtime(true);
    $elapsed = (int) (($now - $startNs) / 1_000_000_000);

    if ($elapsed >= $nextReport || $elapsed >= $durationSec) {
        $d = $delivered->get();
        $avg = $elapsed > 0
            ? $d / $elapsed
            : 0;
        $dt = ($now - $prevTime) / 1_000_000_000;
        $inst = $dt > 0
            ? ($d - $prevDelivered) / $dt
            : 0;

        printf(
            "  %-6ss %-15s %-15s %-15s\n",
            $elapsed,
            number_format($d),
            number_format($avg, 0),
            number_format($inst, 0),
        );

        $prevDelivered = $d;
        $prevTime = $now;
        $nextReport = $elapsed + 5;
    }
}

$stopSignal->set(1);
echo "\n  Stopping...\n";

foreach ($threads as $thread) {
    $thread->join();
}

$endNs = hrtime(true);
$totalMs = ($endNs - $startNs) / 1_000_000;
$finalDelivered = $results['final_delivered'] ?? $delivered->get();
$finalSent = $results['final_sent'] ?? $sent->get();
$throughput = $totalMs > 0
    ? $finalDelivered / $totalMs * 1000
    : 0;
$loss = max(0, (int) $finalSent - (int) $finalDelivered);

echo "\n=== Results ===\n\n";
echo "  Delivered:    " . number_format((int) $finalDelivered) . "\n";
echo "  Sent:         " . number_format((int) $finalSent) . "\n";
echo "  Loss:         {$loss}\n";
echo "  Throughput:   " . number_format($throughput, 0) . " msgs/sec\n";
echo "  Per-worker:   " . number_format($throughput / max($workerCount, 1), 0) . " msgs/sec\n";
echo "\n";
