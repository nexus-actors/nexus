<?php

/**
 * Sustained multi-producer throughput benchmark — N senders × N workers.
 *
 * Each producer thread owns one worker queue (1-producer : 1-consumer),
 * eliminating inter-producer mutex contention and saturating all CPU cores.
 *
 * Optimisations:
 *   - One pre-allocated Envelope per sender (no per-message ULID generation)
 *   - Batch push loop (check time every $batchSize iterations)
 *   - Stats collected via shared Map; main thread aggregates every $reportInterval
 *
 * Usage:
 *   docker compose exec php-swoole php tests/Performance/sustained_benchmark.php
 *   docker compose exec php-swoole php tests/Performance/sustained_benchmark.php 300 16
 */

declare(strict_types=1);

// Locate autoloader before any class references.
$autoloader = null;

foreach (get_included_files() as $file) {
    if (str_ends_with($file, 'vendor/autoload.php')) {
        $autoloader = $file;

        break;
    }
}

if ($autoloader === null) {
    foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $candidate) {
        if (file_exists($candidate)) {
            $autoloader = $candidate;

            break;
        }
    }
}

if ($autoloader === null) {
    fwrite(STDERR, "ERROR: Cannot locate vendor/autoload.php\n");
    exit(1);
}

require_once $autoloader;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPool;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolHandle;
use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Map;

$durationSeconds = (int) ($argv[1] ?? 300);
$senderCount     = (int) ($argv[2] ?? 16); // producer threads; default = worker count
$reportInterval  = 10;

WorkerPool::withThreads(16)
    ->withName('bench')
    ->behavior('sink', static fn(): Behavior => Behavior::receive(
        static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
    ))
    ->onStart(
        static function (WorkerPoolHandle $handle) use ($durationSeconds, $reportInterval, $senderCount, $autoloader): void {
            $workerCount  = $handle->workerCount();
            $queues       = $handle->queues();
            $senderScript = __DIR__ . '/producer_thread.php';

            fwrite(STDERR, sprintf(
                "\n  Sustained benchmark — %d workers, %d senders, %ds duration\n  Workers ready. Starting senders...\n\n",
                $workerCount,
                $senderCount,
                $durationSeconds,
            ));

            $stopSignal = new Atomic(0);
            $statsMap   = new Map();

            /** @var list<\Swoole\Thread> $senderThreads */
            $senderThreads = [];

            for ($s = 0; $s < $senderCount; $s++) {
                /** @psalm-suppress UndefinedClass */
                $senderThreads[] = new Thread(
                    $senderScript,
                    $autoloader,
                    $queues,
                    $workerCount,
                    $s,
                    $stopSignal,
                    $statsMap,
                );
            }

            $endTime       = time() + $durationSeconds;
            $nextReport    = time() + $reportInterval;
            $lastTotal     = 0;
            $intervalStart = hrtime(true);
            $globalStart   = hrtime(true);

            while (true) {
                usleep(100_000); // 100 ms poll interval

                if (time() >= $endTime) {
                    break;
                }

                if (time() >= $nextReport) {
                    $totalSent = 0;

                    for ($s = 0; $s < $senderCount; $s++) {
                        $totalSent += (int) ($statsMap["sent_{$s}"] ?? 0);
                    }

                    $intervalNs   = hrtime(true) - $intervalStart;
                    $intervalMs   = $intervalNs / 1_000_000;
                    $intervalSent = $totalSent - $lastTotal;
                    $opsPerSec    = $intervalMs > 0.0
                        ? (float) $intervalSent / $intervalMs * 1000.0
                        : 0.0;
                    $elapsed = $durationSeconds - ($endTime - time());

                    fwrite(STDERR, sprintf(
                        "  t=+%3ds  sent: %12s  throughput: %10s msg/sec\n",
                        $elapsed,
                        number_format($totalSent),
                        number_format((int) $opsPerSec),
                    ));

                    $lastTotal     = $totalSent;
                    $intervalStart = hrtime(true);
                    $nextReport    = time() + $reportInterval;
                }
            }

            $stopSignal->set(1);

            foreach ($senderThreads as $thread) {
                /** @psalm-suppress UndefinedClass */
                $thread->join();
            }

            $handle->stop();

            // Collect final totals after all senders have stopped.
            $totalSent = 0;

            for ($s = 0; $s < $senderCount; $s++) {
                $totalSent += (int) ($statsMap["sent_{$s}"] ?? 0);
            }

            $globalMs     = (hrtime(true) - $globalStart) / 1_000_000;
            $avgOpsPerSec = $globalMs > 0.0
                ? (float) $totalSent / $globalMs * 1000.0
                : 0.0;

            fwrite(STDERR, sprintf(
                "\n  ── Final Results ─────────────────────────────────────\n" .
                "  Duration:   %.1fs\n" .
                "  Workers:    %d\n" .
                "  Senders:    %d\n" .
                "  Sent:       %s messages\n" .
                "  Throughput: %s msg/sec (avg)\n" .
                "  ──────────────────────────────────────────────────────\n",
                $globalMs / 1000.0,
                $workerCount,
                $senderCount,
                number_format($totalSent),
                number_format((int) $avgOpsPerSec),
            ));
        },
    )
    ->run();
