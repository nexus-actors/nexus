<?php

/**
 * Standalone child-process entry script for SwooleThreadServer WebSocket
 * integration tests.
 *
 * SWOOLE_THREAD mode re-runs the entry script in every worker thread, so the
 * child cannot be a phpunit re-entry. This script is launched via
 * Swoole\Process::exec(PHP_BINARY, [thisScript, host, port, threads]),
 * which replaces the child's image with a fresh PHP interpreter running just
 * this file.
 *
 * Boots a 2-thread SwooleThreadServer that:
 *   - GET /ws-up → 200 OK (sanity probe used by the fixture's TCP poller).
 *   - WS  /ws/echo (handler mode) → echoes `echo:<text>` back to the client.
 *
 * Channel-mode WebSocket routes are not supported in thread mode and are
 * rejected at boot with UnsupportedRouteException. Only handler mode is
 * exercised here.
 *
 * Args:
 *   $argv[1] host     (default 127.0.0.1)
 *   $argv[2] port     (int, required)
 *   $argv[3] threads  (int, default 2)
 */

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Ws\WsApplication;
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
final class ThreadModeEchoHandler extends WebSocketHandler
{
    public function __construct(#[FromContext] private readonly WebSocketContext $ctx) {}

    #[Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }
}

/** @var string $host */
$host = $argv[1] ?? '127.0.0.1';
/** @var int $port */
$port = (int) ($argv[2] ?? 0);
/** @var int $threads */
$threads = (int) ($argv[3] ?? 2);

SwooleThreadServer::run(
    config: SwooleThreadConfig::bind($host, $port)
        ->threads($threads)
        ->installSignalHandlers(true)
        ->enableWebSocket(true),
    factory: static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        $app = WsApplication::create($system);
        $app->get('/ws-up', static fn(): ResponseInterface => Response::ok());

        return $app
            ->ws('/ws/echo', ThreadModeEchoHandler::class)
            ->compile();
    },
);
