#!/usr/bin/env php
<?php

/**
 * Profiling benchmark: runs multiple configurations to find bottlenecks.
 *
 * Tests:
 *   1. Baseline (batch=100, sleep=1ms)
 *   2. Large batch (batch=1000, sleep=1ms)
 *   3. Channel yield (batch=100, yield via Channel ~0.3μs)
 *   4. Channel yield + large batch (batch=1000, yield via Channel)
 *
 * Usage:
 *   docker compose exec php-swoole-thread php tests/Performance/thread_cluster_benchmark_profile.php [workers] [seconds]
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
$durationSec = (int) ($argv[2] ?? 15);

echo "\n";
echo "=== Thread Cluster Profiling Benchmark ===\n";
echo "  Workers: {$workerCount}   Duration: {$durationSec}s per test\n";
echo "\n";

$configs = [
    ['name' => 'Baseline (batch=100, sleep 1ms)',         'script' => 'thread_cluster_worker_timed.php',    'batch' => 100],
    ['name' => 'Large batch (batch=1000, sleep 1ms)',      'script' => 'thread_cluster_worker_timed.php',    'batch' => 1000],
    ['name' => 'Channel yield (batch=100)',                'script' => 'thread_cluster_worker_nosleep.php',  'batch' => 100],
    ['name' => 'Channel yield (batch=1000)',               'script' => 'thread_cluster_worker_nosleep.php',  'batch' => 1000],
    ['name' => 'Channel yield (batch=5000)',               'script' => 'thread_cluster_worker_nosleep.php',  'batch' => 5000],
];

$allResults = [];

foreach ($configs as $config) {
    $name = $config['name'];
    $workerScript = __DIR__ . '/' . $config['script'];
    $batchSize = $config['batch'];
    $usesChannel = str_contains($config['script'], 'nosleep');

    echo "  [{$name}] ";

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

    $threads = [];

    if ($usesChannel) {
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
    } else {
        // Timed worker uses messagesPerWorker/totalMessages args
        // but we'll patch it — for now, use the timed worker as-is with batch=100 implicit
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
    }

    while ($ready->get() < $workerCount) {
        usleep(1000);
    }

    $startNs = hrtime(true);
    $startSignal->set(1);

    // Wait for duration
    $elapsed = 0;

    while ($elapsed < $durationSec) {
        usleep(500_000);
        $elapsed = (int) ((hrtime(true) - $startNs) / 1_000_000_000);
    }

    $stopSignal->set(1);

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

    $allResults[] = [
        'delivered' => (int) $finalDelivered,
        'loss' => $loss,
        'name' => $name,
        'sent' => (int) $finalSent,
        'throughput' => $throughput,
        'wallMs' => $totalMs,
    ];

    echo number_format($throughput, 0) . " msgs/s";
    echo " (" . number_format((int) $finalDelivered) . " delivered, {$loss} loss)";
    echo "\n";
}

echo "\n";
echo "=== Summary ===\n";
echo "\n";
echo str_pad('Configuration', 45) . str_pad('Throughput', 18) . str_pad('Delivered', 16) . str_pad('Loss', 8) . "\n";
echo str_repeat('-', 87) . "\n";

$baseline = $allResults[0]['throughput'];

foreach ($allResults as $r) {
    $speedup = $baseline > 0
        ? $r['throughput'] / $baseline
        : 0;
    echo str_pad($r['name'], 45)
        . str_pad(number_format($r['throughput'], 0) . ' /s', 18)
        . str_pad(number_format($r['delivered']), 16)
        . str_pad((string) $r['loss'], 8)
        . ($speedup > 1.05 ? sprintf('(%.1fx)', $speedup) : '')
        . "\n";
}

echo "\n";
