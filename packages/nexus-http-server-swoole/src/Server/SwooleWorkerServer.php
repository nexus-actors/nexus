<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Nyholm\Psr7\ServerRequest;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

use function microtime;

/**
 * @psalm-api
 *
 * Worker-mode HTTP+WebSocket runner. Builds a per-worker
 * CompiledApplication via the user-supplied factory; wires Swoole
 * Request to handle(), and (if hasWebSocketRoutes) Open/Message/Close
 * to the dispatcher.
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

        $enableWebSocket = $config->enableWebSocket;

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
                    self::recordFailureAndMaybeShutdown($s, $config, $runtime);
                }
            },
        );

        $server->on('Request', static function (Request $req, Response $res) use ($config, $runtime): void {
            try {
                $app = $runtime->app;

                if ($app === null) {
                    $res->status(503);
                    $res->end('Service not ready');

                    return;
                }

                $psr7 = SwooleRequestTranslator::toPsr7($req);
                SwooleResponseWriter::write($app->handle($psr7), $res);
            } catch (Throwable $e) {
                $config->logger->error('Request handling failed', ['exception' => $e]);

                if (!$res->isWritable()) {
                    return;
                }

                $res->status(500);
                $res->end('Internal Server Error');
            }
        });

        if ($enableWebSocket) {
            $server->on('Open', static function (WebSocketServer $s, Request $req) use ($config, $runtime): void {
                try {
                    $app = $runtime->app;

                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }

                    $psr7 = SwooleRequestTranslator::toPsr7($req);
                    $ctx = new SwooleConnectionContext($s, (int) $req->fd, $psr7);
                    $app->dispatcher()->dispatchOpen($ctx, $psr7);
                } catch (Throwable $e) {
                    $config->logger->error('WebSocket Open failed', ['exception' => $e]);
                    $s->disconnect((int) $req->fd, 1011, 'Server error');
                }
            });

            $server->on(
                'Message',
                static function (WebSocketServer $s, SwooleFrame $frame) use ($config, $runtime): void {
                    try {
                        $app = $runtime->app;

                        if (!$app instanceof CompiledWsApplication) {
                            return;
                        }

                        $kind = (int) $frame->opcode === 2
                            ? WebSocketFrame::KIND_BINARY
                            : WebSocketFrame::KIND_TEXT;
                        $wsFrame = new WebSocketFrame($kind, (string) $frame->data);
                        $ctx = new SwooleConnectionContext($s, (int) $frame->fd, new ServerRequest('GET', '/'));
                        $app->dispatcher()->dispatchMessage($ctx, $wsFrame);
                    } catch (Throwable $e) {
                        $config->logger->error('WebSocket Message failed', ['exception' => $e]);
                    }
                },
            );

            $server->on('Close', static function (WebSocketServer $s, int $fd) use ($config, $runtime): void {
                try {
                    $app = $runtime->app;

                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }

                    $ctx = new SwooleConnectionContext($s, $fd, new ServerRequest('GET', '/'));
                    $app->dispatcher()->dispatchClose($ctx, 1000);
                } catch (Throwable $e) {
                    $config->logger->error('WebSocket Close failed', ['exception' => $e]);
                }
            });
        }

        $server->on(
            'WorkerStop',
            static function (HttpServer|WebSocketServer $s, int $workerId) use ($config, $runtime): void {
                $system = $runtime->system;

                if ($system !== null) {
                    try {
                        $system->shutdown($config->shutdownTimeout);
                    } catch (Throwable $e) {
                        $config->logger->error('System shutdown failed in WorkerStop', [
                            'exception' => $e,
                            'workerId' => $workerId,
                        ]);
                    }
                }

                $runtime->reset();
            },
        );

        if ($config->installSignalHandlers) {
            ShutdownSignalHandler::install($server, $config->logger);
        }

        $server->start();
    }

    private static function recordFailureAndMaybeShutdown(
        HttpServer|WebSocketServer $server,
        SwooleWorkerConfig $config,
        WorkerServerRuntime $runtime,
    ): void {
        $now = microtime(true);
        $bucket = $runtime->failureBucket;

        if ($bucket['since'] === 0.0 || $now - $bucket['since'] > 5.0) {
            $bucket = ['count' => 1, 'since' => $now];
        } else {
            $bucket['count']++;
        }

        $runtime->failureBucket = $bucket;

        if ($bucket['count'] >= 3) {
            $config->logger->error('HTTP factory failed during worker boot 3 times in 5s — shutting down master.');
            $server->shutdown();
        }
    }
}
