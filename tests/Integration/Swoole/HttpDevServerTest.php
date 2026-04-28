<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Http\Swoole\HttpServerBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\Process;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;
use function posix_kill;
use function random_int;
use function restore_error_handler;
use function set_error_handler;
use function Swoole\Coroutine\run;
use function usleep;

use const SIGTERM;

/**
 * Integration test that boots a real Swoole HTTP server in a forked child
 * process, round-trips a single GET request from the parent, then terminates
 * the child cleanly.
 *
 * Forking avoids signalling the PHPUnit runner — sending SIGTERM to
 * `getmypid()` from inside the test would abort PHPUnit before the assertions
 * run.
 */
#[CoversClass(HttpServerBootstrap::class)]
final class HttpDevServerTest extends TestCase
{
    #[Test]
    public function dev_server_serves_a_basic_route(): void
    {
        $port = random_int(20000, 30000);

        // enable_coroutine=false on the child Process: Swoole\Http\Server
        // creates its own reactor inside start(). Pre-starting the coroutine
        // event loop here would conflict ("The event-loop has already been
        // created, unable to start Swoole\Http\Server").
        $child = new Process(static function () use ($port): void {
            // PHPUnit installs an error handler that throws on any notice and
            // walks the call stack looking for the active TestCase. Neither
            // makes sense in this forked server process, so pop the entire
            // handler stack and replace it with a swallowing handler.
            for ($i = 0; $i < 16; $i++) {
                restore_error_handler();
            }

            set_error_handler(static fn(): bool => true);

            $route = get(static fn() => path('hello', static fn() => complete(['msg' => 'hi'])));
            HttpServerBootstrap::dev($route)
                ->host('127.0.0.1')
                ->port($port)
                ->run();
        }, false, 0, false);

        $childPid = $child->start();
        self::assertNotFalse($childPid, 'failed to fork server child process');

        // Wait for the server to bind. 800ms is generous for in-container
        // boot + bind on a random port.
        usleep(800_000);

        $body       = null;
        $statusCode = null;
        $errCode    = null;

        run(static function () use ($port, &$body, &$statusCode, &$errCode): void {
            $client = new Client('127.0.0.1', $port);
            $client->setHeaders(['Accept' => 'application/json']);
            $client->set(['timeout' => 2.0]);
            $client->get('/hello');
            $body       = $client->body;
            $statusCode = $client->statusCode;
            $errCode    = $client->errCode;
            $client->close();
        });

        // Tear down the child server.
        posix_kill($childPid, SIGTERM);
        Process::wait(true);

        self::assertSame(200, $statusCode, "client errCode={$errCode}");
        self::assertSame('{"msg":"hi"}', $body);
    }
}
