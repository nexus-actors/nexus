<?php

/**
 * Sustained 16-thread throughput benchmark — uses WorkerPool DSL with onStart.
 *
 * Key optimisations over the naive loop:
 *   - Pre-allocates one Envelope per worker (eliminates per-message ULID generation)
 *   - Checks time/reports every $batchSize iterations (eliminates per-message time() call)
 *   - Workers defined via ->behavior() DSL — no hand-rolled thread script needed
 *
 * Usage:
 *   docker compose exec php-swoole php tests/Performance/sustained_benchmark.php
 *   docker compose exec php-swoole php tests/Performance/sustained_benchmark.php 300
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
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPool;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolHandle;
use Swoole\Thread\Queue;

$durationSeconds = (int) ($argv[1] ?? 300);
$reportInterval  = 10;
$batchSize       = 10_000; // push this many messages before checking time

WorkerPool::withThreads(16)
    ->withName('bench')
    ->behavior('sink', static fn(): Behavior => Behavior::receive(
        static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
    ))
    ->onStart(static function (WorkerPoolHandle $handle) use ($durationSeconds, $reportInterval, $batchSize): void {
        $workerCount = $handle->workerCount();
        $queues      = $handle->queues();

        fwrite(STDERR, sprintf(
            "\n  Sustained benchmark — %d workers, %ds duration\n  Workers ready. Sending...\n\n",
            $workerCount,
            $durationSeconds,
        ));

        // Pre-allocate one reusable Envelope per worker.
        // Envelope is final readonly — safe to reuse for throughput testing.
        $senderPath = ActorPath::root();

        /** @var array<int, Envelope> $envelopes */
        $envelopes = [];

        for ($w = 0; $w < $workerCount; $w++) {
            $envelopes[$w] = Envelope::of(
                new stdClass(),
                $senderPath,
                ActorPath::fromString('/user/sink'),
            );
        }

        $endTime       = time() + $durationSeconds;
        $nextReport    = time() + $reportInterval;
        $totalSent     = 0;
        $intervalSent  = 0;
        $intervalStart = hrtime(true);
        $globalStart   = hrtime(true);
        $i             = 0;

        while (true) {
            // Tight batch — no per-message time() or modulo recomputation overhead.
            for ($b = 0; $b < $batchSize; $b++) {
                $queues[$i % $workerCount]->push($envelopes[$i % $workerCount], Queue::NOTIFY_ONE);
                $i++;
            }

            $totalSent    += $batchSize;
            $intervalSent += $batchSize;

            if (time() >= $endTime) {
                break;
            }

            if (time() >= $nextReport) {
                $intervalNs = hrtime(true) - $intervalStart;
                $intervalMs = $intervalNs / 1_000_000;
                $opsPerSec  = $intervalMs > 0.0
                    ? (float) $intervalSent / $intervalMs * 1000.0
                    : 0.0;
                $elapsed = $durationSeconds - ($endTime - time());

                fwrite(STDERR, sprintf(
                    "  t=+%3ds  sent: %12s  throughput: %10s msg/sec\n",
                    $elapsed,
                    number_format($totalSent),
                    number_format((int) $opsPerSec),
                ));

                $intervalSent  = 0;
                $intervalStart = hrtime(true);
                $nextReport    = time() + $reportInterval;
            }
        }

        $handle->stop();

        $globalMs   = (hrtime(true) - $globalStart) / 1_000_000;
        $avgOpsPerSec = $globalMs > 0.0
            ? (float) $totalSent / $globalMs * 1000.0
            : 0.0;

        fwrite(STDERR, sprintf(
            "\n  ── Final Results ─────────────────────────────────────\n" .
            "  Duration:   %.1fs\n" .
            "  Workers:    %d\n" .
            "  Sent:       %s messages\n" .
            "  Throughput: %s msg/sec (avg)\n" .
            "  ──────────────────────────────────────────────────────\n",
            $globalMs / 1000.0,
            $workerCount,
            number_format($totalSent),
            number_format((int) $avgOpsPerSec),
        ));
    })
    ->run();
