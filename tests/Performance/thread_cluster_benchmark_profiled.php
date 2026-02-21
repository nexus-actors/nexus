#!/usr/bin/env php
<?php

/**
 * Profiled Thread Cluster benchmark — instruments each pipeline stage.
 *
 * Usage:
 *   docker compose exec php-swoole-thread php tests/Performance/thread_cluster_benchmark_profiled.php [workers] [seconds]
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
$workerScript = __DIR__ . '/thread_cluster_profiled_worker.php';

echo "\n=== Profiled Thread Cluster Benchmark ({$workerCount} threads, {$durationSec}s) ===\n\n";

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

while ($ready->get() < $workerCount) {
    usleep(1000);
}

echo "  Running for {$durationSec}s...\n";

$startNs = hrtime(true);
$startSignal->set(1);

sleep($durationSec);

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

echo "  Throughput: " . number_format($throughput, 0) . " msgs/sec\n";
echo "  Delivered:  " . number_format((int) $finalDelivered) . " / " . number_format((int) $finalSent) . "\n\n";

// Aggregate profiling data from all workers
$totals = [
    'actorNs' => 0,
    'emptyPops' => 0,
    'handlerCount' => 0,
    'handlerNs' => 0,
    'localSent' => 0,
    'popCount' => 0,
    'popNs' => 0,
    'pushNs' => 0,
    'sendLoopNs' => 0,
    'sendSleepNs' => 0,
    'sleepNs' => 0,
];

for ($i = 0; $i < $workerCount; $i++) {
    $key = "prof_{$i}";

    if (!isset($results[$key])) {
        continue;
    }

    $data = json_decode($results[$key], true);

    foreach ($totals as $k => &$v) {
        $v += $data[$k] ?? 0;
    }
}

$totalSent = $totals['localSent'];
$totalReceived = $totals['handlerCount'];

echo "=== Per-Message Costs (averaged across {$workerCount} workers) ===\n\n";

$fmt = static function (string $label, int $totalNs, int $count, string $note = '') use ($workerCount): void {
    if ($count === 0) {
        return;
    }

    $avgNs = $totalNs / $count;
    $avgUs = $avgNs / 1000;
    $totalMs = $totalNs / 1_000_000;
    $perWorkerMs = $totalMs / $workerCount;
    $pct = '';

    printf(
        "  %-30s %8.2f μs/op   %10s ms total   %s\n",
        $label,
        $avgUs,
        number_format($perWorkerMs, 0),
        $note,
    );
};

echo "  SEND PATH (per message sent):\n";
$fmt('Queue::push()', $totals['pushNs'], $totalSent, 'Thread\Queue C extension');
$fmt('Send loop (batch of 100)', $totals['sendLoopNs'], (int) ($totalSent / 100), 'Envelope + tell + push');
$fmt('Send sleep(0.001)', $totals['sendSleepNs'], (int) ($totalSent / 100), '*** YIELD ***');

$sendBatchAvgUs = $totalSent > 0
    ? $totals['sendLoopNs'] / ($totalSent / 100) / 1000
    : 0;
$sendSleepAvgUs = $totalSent > 0
    ? $totals['sendSleepNs'] / ($totalSent / 100) / 1000
    : 0;
$sendTotalUs = $sendBatchAvgUs + $sendSleepAvgUs;

echo "\n  RECEIVE PATH (per message received):\n";
$fmt('Queue::pop()', $totals['popNs'], $totals['popCount'], "({$totals['emptyPops']} empty pops)");
$fmt('Receive sleep()', $totals['sleepNs'], $totals['emptyPops'], '*** YIELD (only on empty) ***');
$fmt('ClusterNode handler', $totals['handlerNs'], $totalReceived, 'path lookup + enqueue');
$fmt('Actor handler', $totals['actorNs'], $totalReceived, 'Atomic::add(1)');

echo "\n=== Time Budget Breakdown ===\n\n";

$totalRunNs = $totalMs * 1_000_000 * $workerCount;

$components = [
    'Actor handler' => $totals['actorNs'],
    'ClusterNode handler' => $totals['handlerNs'],
    'Queue::pop (receive)' => $totals['popNs'],
    'Queue::push (send)' => $totals['pushNs'],
    'Receive sleep (yield)' => $totals['sleepNs'],
    'Send loop work' => $totals['sendLoopNs'] - $totals['pushNs'],
    'Send sleep (yield)' => $totals['sendSleepNs'],
];

$measured = array_sum($components);
$unmeasured = max(0, $totalRunNs - $measured);
$components['Unmeasured (scheduling, GC, ...)'] = (int) $unmeasured;

echo str_pad('Component', 42) . str_pad('Time', 14) . str_pad('%', 8) . "Per-msg\n";
echo str_repeat('-', 75) . "\n";

foreach ($components as $name => $ns) {
    $pct = $totalRunNs > 0
        ? $ns / $totalRunNs * 100
        : 0;
    $perMsgUs = $totalSent > 0
        ? $ns / $totalSent / 1000
        : 0;
    printf(
        "  %-40s %10s ms  %5.1f%%   %.2f μs\n",
        $name,
        number_format((int) ($ns / 1_000_000)),
        $pct,
        $perMsgUs,
    );
}

echo str_repeat('-', 75) . "\n";
printf(
    "  %-40s %10s ms  100.0%%\n",
    'Total (wall * workers)',
    number_format((int) ($totalRunNs / 1_000_000)),
);

echo "\n=== Key Metrics ===\n\n";

$popHitRate = $totals['popCount'] > 0
        ? (1 - $totals['emptyPops'] / $totals['popCount']) * 100
        : 0;

echo "  Pop hit rate:           " . number_format($popHitRate, 1) . "% (message found on pop)\n";
echo "  Avg push latency:      " . number_format(
    $totalSent > 0 ? $totals['pushNs'] / $totalSent / 1000 : 0,
    2,
) . " μs\n";
echo "  Avg pop latency:       " . number_format(
    $totals['popCount'] > 0 ? $totals['popNs'] / $totals['popCount'] / 1000 : 0,
    2,
) . " μs\n";
echo "  Send cycle (100 msgs): " . number_format($sendTotalUs, 0) . " μs (work: " . number_format(
    $sendBatchAvgUs,
    0,
) . " + sleep: " . number_format($sendSleepAvgUs, 0) . ")\n";

echo "\n";
