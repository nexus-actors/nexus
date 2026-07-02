<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\WorkerPoolSwoole\Support;

use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Override;

use function file_put_contents;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;
use const LOCK_EX;

/**
 * @psalm-api
 *
 * Minimal WorkerPoolApp used by PoolBootIntegrationTest to prove that
 * Swoole\Thread\Pool actually instantiates WorkerRunnable and dispatches
 * arguments through run($args). Each worker appends its workerId to a
 * well-known heartbeat file under sys_get_temp_dir(), allowing the parent
 * process to verify per-thread startup.
 *
 * NOTE: A fixed path is used because Swoole worker threads each have their
 * own PHP runtime — static properties set in the parent process do NOT
 * propagate into worker threads via Pool::withArguments().
 */
final class RecordingWorkerPoolApp extends WorkerPoolApp
{
    public static function heartbeatPath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexus-pool-boot-heartbeat.log';
    }

    #[Override]
    protected function configure(WorkerNode $node): void
    {
        file_put_contents(
            self::heartbeatPath(),
            $node->workerId() . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }
}
