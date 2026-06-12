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

#[CoversNothing]
final class ThreadModeHttpIntegrationTest extends TestCase
{
    #[Test]
    public function serves_compiled_http_app_in_thread_mode(): void
    {
        $port    = ForkedSwooleServerFixture::findFreePort();
        $fixture = new ForkedSwooleServerFixture('127.0.0.1', $port);
        $script  = __DIR__ . '/Support/thread_http_server_bootstrap.php';

        $fixture->start(static function (Process $worker) use ($script, $port): void {
            // SWOOLE_THREAD re-runs the entry script in every worker thread.
            // Swap the child's process image to a fresh PHP interpreter
            // running just our bootstrap so phpunit doesn't get re-executed.
            $worker->exec(PHP_BINARY, [$script, '127.0.0.1', (string) $port, '2']);
        });

        try {
            $statusCode = null;
            run(static function () use ($port, &$statusCode): void {
                $client = new Client('127.0.0.1', $port);
                $client->get('/hello');
                $statusCode = $client->statusCode;
                $client->close();
            });

            self::assertSame(200, $statusCode);
        } finally {
            $fixture->shutdown();
        }
    }
}
