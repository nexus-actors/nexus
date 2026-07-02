<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\WorkerPoolSwoole;

use Monadial\Nexus\Tests\Integration\WorkerPoolSwoole\Support\RecordingWorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Process;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function microtime;
use function sort;
use function unlink;
use function usleep;

use const SIGTERM;

/**
 * Integration test that proves WorkerPoolApp::run() actually boots
 * Swoole\Thread\Pool end-to-end. Guards against the latent constructor-args
 * contract bug in WorkerRunnable: if WorkerRunnable's constructor or
 * run($args) wiring is wrong, the workers either fail to start or skip
 * configure() — and the heartbeat file stays empty.
 *
 * The test is intentionally minimal: spawn a child process that boots the
 * pool with 2 workers, wait for each worker's configure() to write its
 * workerId to a heartbeat file, then assert that both IDs appear.
 */
#[CoversNothing]
#[RequiresPhpExtension('swoole')]
final class PoolBootIntegrationTest extends TestCase
{
    private string $heartbeatPath = '';

    #[Test]
    public function pool_boots_workers_and_each_runs_configure(): void
    {
        $workerCount = 2;

        $proc = new Process(static function () use ($workerCount): void {
            RecordingWorkerPoolApp::run(WorkerPoolConfig::withThreads($workerCount));
        });

        $pid = $proc->start();
        self::assertNotFalse($pid, 'Failed to spawn child process');

        try {
            $deadline = microtime(true) + 5.0;
            $ids      = [];

            while (microtime(true) < $deadline) {
                $ids = $this->readWorkerIds();

                if (count($ids) >= $workerCount) {
                    break;
                }

                usleep(50_000);
            }

            sort($ids);
            self::assertSame([0, 1], $ids, 'Expected both worker IDs to be recorded');
        } finally {
            Process::kill($pid, SIGTERM);
            Process::wait(true);
        }
    }

    protected function setUp(): void
    {
        $this->heartbeatPath = RecordingWorkerPoolApp::heartbeatPath();

        if (file_exists($this->heartbeatPath)) {
            @unlink($this->heartbeatPath);
        }
    }

    protected function tearDown(): void
    {
        if ($this->heartbeatPath !== '' && file_exists($this->heartbeatPath)) {
            @unlink($this->heartbeatPath);
        }
    }

    /** @return list<int> */
    private function readWorkerIds(): array
    {
        if (! file_exists($this->heartbeatPath)) {
            return [];
        }

        $contents = @file_get_contents($this->heartbeatPath);

        if ($contents === false || $contents === '') {
            return [];
        }

        $lines = array_filter(
            explode("\n", $contents),
            static fn(string $l): bool => $l !== '',
        );

        $ids = array_unique(array_map(static fn(string $l): int => (int) $l, $lines));

        return array_values($ids);
    }
}
