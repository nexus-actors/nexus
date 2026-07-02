<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\Process;

use function Co\run;

use const PHP_BINARY;

/**
 * @psalm-api
 *
 * Verifies SwooleThreadServer WebSocket wiring end-to-end with a real
 * round-trip in SWOOLE_THREAD mode. Channel-mode routes are rejected at
 * boot (see ThreadModeChannelRejectionTest). This test exercises the fully
 * supported path: handler-mode echo through a 2-thread server.
 */
#[CoversNothing]
final class ThreadModeWebSocketIntegrationTest extends TestCase
{
    #[Test]
    public function handler_mode_websocket_works_in_thread_mode(): void
    {
        $port    = ForkedSwooleServerFixture::findFreePort();
        $fixture = new ForkedSwooleServerFixture('127.0.0.1', $port);
        $script  = __DIR__ . '/Support/thread_websocket_server_bootstrap.php';

        $fixture->start(static function (Process $worker) use ($script, $port): void {
            // SWOOLE_THREAD re-runs the entry script in every worker thread;
            // swap the child image to a fresh PHP interpreter running just
            // the bootstrap so phpunit is not re-executed per thread.
            $worker->exec(PHP_BINARY, [$script, '127.0.0.1', (string) $port, '2']);
        });

        try {
            $received = null;
            run(static function () use ($port, &$received): void {
                $client = new Client('127.0.0.1', $port);
                $client->upgrade('/ws/echo');
                $client->push('hi');
                $frame      = $client->recv(2.0);
                $received   = $frame === false || $frame === true
                    ? null
                    : $frame->data;
                $client->close();
            });

            self::assertSame('echo:hi', $received);
        } finally {
            $fixture->shutdown();
        }
    }
}
