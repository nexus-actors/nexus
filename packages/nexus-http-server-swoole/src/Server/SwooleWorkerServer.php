<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleServerEventBinder;
use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

/**
 * @psalm-api
 *
 * Worker-mode HTTP+WebSocket runner. Builds a per-worker
 * CompiledApplication via the user-supplied factory; wires Swoole
 * Request to handle(), and (if enableWebSocket) Open/Message/Close
 * to the dispatcher via SwooleServerEventBinder.
 */
final class SwooleWorkerServer
{
    /** @param Closure(ActorSystem): CompiledApplication $factory */
    public static function run(SwooleWorkerConfig $config, Closure $factory): void
    {
        $runtime = new WorkerServerRuntime();
        $server = $config->enableWebSocket
            ? new WebSocketServer($config->host, $config->port)
            : new HttpServer($config->host, $config->port);

        $settings = [
            'dispatch_mode' => $config->dispatchMode,
            'max_conn' => $config->maxConn,
            'max_request' => $config->maxRequest,
            'reactor_num' => $config->reactorThreads,
            'worker_num' => $config->workers,
        ];

        if ($config->logFile !== '') {
            $settings['log_file'] = $config->logFile;
        }

        $server->set($settings);

        $server->on(
            'WorkerStart',
            static function (HttpServer|WebSocketServer $s, int $workerId) use ($factory, $config, $runtime): void {
                try {
                    $system = ActorSystem::create("http-worker-{$workerId}", new SwooleRuntime());
                    $app = $factory($system);
                    $runtime->system = $system;
                    $runtime->app = $app;
                } catch (Throwable $e) {
                    $config->logger->error('HTTP factory failed during WorkerStart', [
                        'exception' => $e,
                        'workerId' => $workerId,
                    ]);
                    SwooleServerEventBinder::recordFailureAndMaybeShutdown(
                        $s,
                        $runtime,
                        $config->logger,
                        'HTTP factory failed during worker boot 3 times in 5s — shutting down master.',
                    );
                }
            },
        );

        SwooleServerEventBinder::bindRequest($server, $runtime, $config->logger);

        if ($config->enableWebSocket) {
            assert($server instanceof WebSocketServer);
            SwooleServerEventBinder::bindWebSocket(
                $server,
                $runtime,
                static fn(WebSocketServer $s, int $fd, ServerRequestInterface $req): WebSocketContext => new SwooleConnectionContext(
                    $s,
                    $fd,
                    $req,
                ),
                $config->logger,
            );
        }

        SwooleServerEventBinder::bindWorkerStop($server, $runtime, $config->shutdownTimeout, $config->logger);

        if ($config->installSignalHandlers) {
            ShutdownSignalHandler::install($server, $config->logger);
        }

        $server->start();
    }
}
