<?php

declare(strict_types=1);

use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Queue;

/**
 * Dedicated log-writer thread for the nexus-logger ThreadQueueHandler
 * prototype. Spawned as a Swoole\Thread alongside SwooleThreadServer.
 *
 * Drains a shared Swoole\Thread\Queue and writes each popped formatted
 * line to a file with no locks (single writer, single fd).
 *
 * Started from the parent like:
 *   new Swoole\Thread(__DIR__ . '/logger-writer.php', $queue, $path, $shutdown);
 */

require_once __DIR__ . '/../vendor/autoload.php';

/** @var array{0: Queue, 1: string, 2: Atomic} $args */
$args = Thread::getArguments();
$queue = $args[0];
$path = $args[1];
$shutdown = $args[2];

$fp = fopen($path, 'ab');

if ($fp === false) {
    fwrite(STDERR, "logger-writer: cannot open {$path}\n");
    exit(1);
}

$flushEvery = 100;
$writtenSinceFlush = 0;

while ($shutdown->get() === 0) {
    /** @var string|false|null $line */
    $line = $queue->pop(0.1);

    if ($line === null || $line === false) {
        if ($writtenSinceFlush > 0) {
            fflush($fp);
            $writtenSinceFlush = 0;
        }

        continue;
    }

    fwrite($fp, $line . "\n");
    $writtenSinceFlush++;

    if ($writtenSinceFlush >= $flushEvery) {
        fflush($fp);
        $writtenSinceFlush = 0;
    }
}

while (true) {
    /** @var string|false|null $line */
    $line = $queue->pop(0.01);

    if ($line === null || $line === false) {
        break;
    }

    fwrite($fp, $line . "\n");
}

fflush($fp);
fclose($fp);
