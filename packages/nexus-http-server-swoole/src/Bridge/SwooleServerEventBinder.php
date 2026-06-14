<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Bridge;

use Closure;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
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
 * Wires Swoole HTTP+WebSocket events to a CompiledApplication. Shared by
 * both worker- and thread-mode runners; the only per-mode variability is
 * the WebSocketContext factory injected into bindWebSocket().
 */
final class SwooleServerEventBinder
{
    public static function bindRequest(
        HttpServer|WebSocketServer $server,
        ServerRuntime $runtime,
        LoggerInterface $logger,
    ): void {
        $logger->debug('SwooleServerEventBinder: binding Request handler');

        $server->on('Request', static function (Request $req, Response $res) use ($runtime, $logger): void {
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
                $logger->error('Request handling failed', ['exception' => $e]);

                if (!$res->isWritable()) {
                    return;
                }

                $res->status(500);
                $res->end('Internal Server Error');
            }
        });
    }

    /**
     * @param Closure(WebSocketServer, int, ServerRequestInterface): WebSocketContext $contextFactory
     */
    public static function bindWebSocket(
        WebSocketServer $server,
        ServerRuntime $runtime,
        Closure $contextFactory,
        LoggerInterface $logger,
    ): void {
        $logger->debug('SwooleServerEventBinder: binding WebSocket Open/Message/Close');
        $server->on(
            'Open',
            static function (WebSocketServer $s, Request $req) use ($runtime, $contextFactory, $logger): void {
                $logger->debug('Swoole Open event', ['fd' => (int) $req->fd]);

                try {
                    $app = $runtime->app;
    
                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }
    
                    $psr7 = SwooleRequestTranslator::toPsr7($req);
                    $ctx = $contextFactory($s, (int) $req->fd, $psr7);
                    $app->dispatcher()->dispatchOpen($ctx, $psr7);
                } catch (Throwable $e) {
                    $logger->error('WebSocket Open failed', ['exception' => $e]);
                    $s->disconnect((int) $req->fd, 1011, 'Server error');
                }
            },
        );

        $server->on(
            'Message',
            static function (WebSocketServer $s, SwooleFrame $frame) use ($runtime, $contextFactory, $logger): void {
                try {
                    $app = $runtime->app;
    
                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }
    
                    $kind = (int) $frame->opcode === 2
                        ? WebSocketFrame::KIND_BINARY
                        : WebSocketFrame::KIND_TEXT;
                    $wsFrame = new WebSocketFrame($kind, (string) $frame->data);
                    $ctx = $contextFactory($s, (int) $frame->fd, new ServerRequest('GET', '/'));
                    $app->dispatcher()->dispatchMessage($ctx, $wsFrame);
                } catch (Throwable $e) {
                    $logger->error('WebSocket Message failed', ['exception' => $e]);
                }
            },
        );

        $server->on(
            'Close',
            static function (WebSocketServer $s, int $fd) use ($runtime, $contextFactory, $logger): void {
                $logger->debug('Swoole Close event', ['fd' => $fd]);

                try {
                    $app = $runtime->app;
    
                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }
    
                    $ctx = $contextFactory($s, $fd, new ServerRequest('GET', '/'));
                    $app->dispatcher()->dispatchClose($ctx, 1000);
                } catch (Throwable $e) {
                    $logger->error('WebSocket Close failed', ['exception' => $e]);
                }
            },
        );
    }

    public static function bindWorkerStop(
        HttpServer|WebSocketServer $server,
        ServerRuntime $runtime,
        Duration $shutdownTimeout,
        LoggerInterface $logger,
    ): void {
        $logger->debug('SwooleServerEventBinder: binding WorkerStop');
        $server->on(
            'WorkerStop',
            static function (HttpServer|WebSocketServer $s, int $workerId) use ($runtime, $shutdownTimeout, $logger): void {
                $logger->info('Worker stopping', ['workerId' => $workerId]);
                $system = $runtime->system;

                if ($system !== null) {
                    try {
                        $system->shutdown($shutdownTimeout);
                        $logger->info('Worker ActorSystem shutdown complete', ['workerId' => $workerId]);
                    } catch (Throwable $e) {
                        $logger->error('System shutdown failed in WorkerStop', [
                            'exception' => $e,
                            'workerId' => $workerId,
                        ]);
                    }
                }

                $runtime->reset();
            },
        );
    }

    /**
     * Sliding-window failure counter — 3 failures within 5s triggers
     * Server::shutdown(). Called by each runner from the WorkerStart catch
     * block; the runner provides a mode-specific shutdown message.
     */
    public static function recordFailureAndMaybeShutdown(
        HttpServer|WebSocketServer $server,
        ServerRuntime $runtime,
        LoggerInterface $logger,
        string $shutdownMessage,
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
            $logger->error($shutdownMessage);
            $server->shutdown();
        }
    }
}
