<?php

/**
 * Standalone child-process entry script for SwooleThreadHttpServer WebSocket
 * integration tests.
 *
 * SWOOLE_THREAD mode re-runs the entry script in every worker thread, so the
 * child cannot be a phpunit re-entry. This script is launched via
 * Swoole\Process::exec(PHP_BINARY, [thisScript, host, port, threads]),
 * which replaces the child's image with a fresh PHP interpreter running just
 * this file.
 *
 * Boots a 2-thread SwooleThreadHttpServer that:
 *   - GET /ws-up → 200 OK (sanity probe used by the fixture's TCP poller).
 *   - WS  /ws/echo (handler mode) → echoes `echo:<text>` back to the client.
 *
 * Phase 16 v1 limitation: channel-mode WebSocket routes are thread-local
 * (their `ChannelConnectionOpened` payload — `WebSocketContext` + Swoole
 * `Request` — is not serialization-safe across Thread\Queue). Handler mode
 * is fully supported in thread mode and is exercised here.
 *
 * Args:
 *   $argv[1] host     (default 127.0.0.1)
 *   $argv[2] port     (int, required)
 *   $argv[3] threads  (int, default 2)
 */

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadHttpServer;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/../../../../vendor/autoload.php';

/**
 * @psalm-api
 *
 * Echo handler defined inline because PSR-4 autoloading does not reach the
 * tests/Integration/HttpSwoole/Support directory; the bootstrap script is the
 * sole consumer.
 */
final class ThreadModeEchoHandler implements WebSocketHandler
{
    public function __construct(private readonly WebSocketContext $ctx) {}

    #[Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }

    #[Override]
    public function onClose(int $closeCode): void
    {
        // no-op
    }
}

/** @var string $host */
$host = $argv[1] ?? '127.0.0.1';
/** @var int $port */
$port = (int) ($argv[2] ?? 0);
/** @var int $threads */
$threads = (int) ($argv[3] ?? 2);

SwooleThreadHttpServer::run(
    config: SwooleThreadConfig::bind($host, $port)
        ->threads($threads)
        ->installSignalHandlers(true),
    factory: static function (ActorSystem $system, WorkerNode $node): SwooleCompiledHttpApp {
        $http = HttpApp::create($system);
        $http->get('/ws-up', static fn(): ResponseInterface => Response::ok());

        return SwooleHttpApp::wrap($http, $system)
            ->webSocket(
                '/ws/echo',
                static fn(WebSocketContext $ctx): WebSocketHandler => new ThreadModeEchoHandler($ctx),
            )
            ->compile();
    },
);
