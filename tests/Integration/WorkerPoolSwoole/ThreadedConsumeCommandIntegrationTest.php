<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\WorkerPoolSwoole;

use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumeCommand;
use Monadial\Nexus\Tests\Integration\WorkerPoolSwoole\Support\HeartbeatThreadedConsumerBootstrap;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Process;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function array_filter;
use function array_values;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function microtime;
use function unlink;
use function usleep;

use const SIGTERM;

/**
 * Integration test that proves ThreadedConsumeCommand boots the Swoole thread
 * pool and invokes ThreadedConsumerBootstrap::setup() on each worker thread.
 *
 * Pattern mirrors PoolBootIntegrationTest: a child process runs the command,
 * workers write heartbeats to a shared temp file, and the parent asserts all
 * threads checked in before sending SIGTERM.
 */
#[CoversNothing]
#[RequiresPhpExtension('swoole')]
final class ThreadedConsumeCommandIntegrationTest extends TestCase
{
    private string $heartbeatPath = '';

    #[Test]
    public function threaded_consume_command_boots_all_worker_threads(): void
    {
        $threadCount    = 2;
        $bootstrapClass = HeartbeatThreadedConsumerBootstrap::class;

        $proc = new Process(static function () use ($bootstrapClass, $threadCount): void {
            $command = new ThreadedConsumeCommand($bootstrapClass);
            $app     = new Application('nexus-test', '0.0.0');
            $app->addCommand($command);
            $app->setAutoExit(false);
            $app->run(
                new ArrayInput([
                    'command'         => 'nexus:messenger:consume-threads',
                    '--threads'       => (string) $threadCount,
                    '--poll-interval' => '50',
                ]),
                new NullOutput(),
            );
        });

        $pid = $proc->start();
        self::assertNotFalse($pid, 'Failed to spawn child process');

        try {
            $deadline = microtime(true) + 8.0;
            $lines    = [];

            while (microtime(true) < $deadline) {
                $lines = $this->readHeartbeatLines();

                if (count($lines) >= $threadCount) {
                    break;
                }

                usleep(50_000);
            }

            self::assertGreaterThanOrEqual(
                $threadCount,
                count($lines),
                'Expected all worker threads to have written a heartbeat line',
            );
        } finally {
            Process::kill($pid, SIGTERM);
            Process::wait(true);
        }
    }

    protected function setUp(): void
    {
        $this->heartbeatPath = HeartbeatThreadedConsumerBootstrap::heartbeatPath();

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

    /** @return list<string> */
    private function readHeartbeatLines(): array
    {
        if (!file_exists($this->heartbeatPath)) {
            return [];
        }

        $contents = @file_get_contents($this->heartbeatPath);

        if ($contents === false || $contents === '') {
            return [];
        }

        return array_values(array_filter(
            explode("\n", $contents),
            static fn(string $l): bool => $l !== '',
        ));
    }
}
