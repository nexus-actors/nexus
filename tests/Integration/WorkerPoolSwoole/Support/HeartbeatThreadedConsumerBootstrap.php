<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\WorkerPoolSwoole\Support;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumerBootstrap;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

use function file_put_contents;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;
use const LOCK_EX;

/**
 * ThreadedConsumerBootstrap used by ThreadedConsumeCommandIntegrationTest to
 * prove that each worker thread invokes setup(). Each thread appends the
 * system name to a shared heartbeat file so the parent process can count them.
 *
 * NOTE: Uses a fixed path under sys_get_temp_dir() — Swoole worker threads
 * each run their own PHP runtime, so static properties set in the parent do
 * NOT propagate into threads via Pool::withArguments().
 *
 * @psalm-api
 */
final class HeartbeatThreadedConsumerBootstrap implements ThreadedConsumerBootstrap
{
    public function setup(ActorSystem $system): MessageRouter
    {
        file_put_contents(
            self::heartbeatPath(),
            $system->name() . "\n",
            FILE_APPEND | LOCK_EX,
        );

        return new MapMessageRouter([]);
    }

    public function receiver(): ReceiverInterface
    {
        return new InMemoryTransport();
    }

    public static function heartbeatPath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexus-threaded-consume-heartbeat.log';
    }
}
