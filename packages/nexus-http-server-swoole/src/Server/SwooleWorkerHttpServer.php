<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ChannelActorNameResolver;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ConnectionTable;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\LocalWebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelMessageReceived;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRoute;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

use function is_array;
use function is_string;
use function microtime;

/**
 * @psalm-api
 *
 * Worker-mode HTTP (and optional WebSocket) runner. Boots ext-swoole's master
 * + N worker processes. Each worker runs the factory at WorkerStart to build a
 * per-worker CompiledHttpApp (or SwooleCompiledHttpApp for WebSocket support).
 *
 * Per-worker state lives on a single WorkerServerRuntime instance captured by
 * the event closures via `use` — no static arrays keyed by object id.
 *
 * WebSocket support is opt-in via SwooleWorkerConfig::enableWebSocket(true):
 * when enabled, the server is a Swoole\WebSocket\Server and Open/Message/Close
 * handlers are registered. When disabled (the default), the server is a plain
 * Swoole\Http\Server and no WebSocket events are wired even if the compiled
 * app declares WebSocket routes.
 *
 * Channel-actor WebSocket routes spawn the channel actor as WorkerLocal —
 * no cross-worker sharing. See spec for thread-mode alternative.
 */
final class SwooleWorkerHttpServer
{
    /** @param Closure(ActorSystem): (CompiledHttpApp|SwooleCompiledHttpApp) $factory */
    public static function run(SwooleWorkerConfig $config, Closure $factory): void
    {
        $runtime = new WorkerServerRuntime();
        $server  = $config->enableWebSocket
            ? new WebSocketServer($config->host, $config->port)
            : new HttpServer($config->host, $config->port);

        $settings = [
            'dispatch_mode' => $config->dispatchMode,
            'max_conn'      => $config->maxConn,
            'max_request'   => $config->maxRequest,
            'reactor_num'   => $config->reactorThreads,
            'worker_num'    => $config->workers,
        ];

        if ($config->logFile !== '') {
            $settings['log_file'] = $config->logFile;
        }

        $server->set($settings);

        $enableWebSocket = $config->enableWebSocket;

        $server->on(
            'WorkerStart',
            static function (HttpServer|WebSocketServer $s, int $workerId) use ($factory, $config, $runtime, $enableWebSocket): void {
                try {
                    $system        = ActorSystem::create("http-worker-{$workerId}", new SwooleRuntime());
                    $app           = $factory($system);
                    $runtime->system = $system;
                    $runtime->app    = $app;

                    if ($enableWebSocket) {
                        $runtime->connections = new ConnectionTable();
                        $runtime->channels    = new ChannelActorRegistry($system);
                    }
                } catch (Throwable $e) {
                    $config->logger->error('HTTP factory failed during WorkerStart', [
                        'exception' => $e,
                        'workerId'  => $workerId,
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
            /** @var WebSocketServer $server */
            $server->on('Open', static function (WebSocketServer $s, Request $req) use ($config, $runtime): void {
                $fd = (int) $req->fd;

                try {
                    $app = $runtime->app;

                    if (!$app instanceof SwooleCompiledHttpApp) {
                        return;
                    }

                    /** @var mixed $serverEnv */
                    $serverEnv = $req->server;
                    $path      = '/';

                    if (
                        is_array($serverEnv)
                        && isset($serverEnv['request_uri'])
                        && is_string($serverEnv['request_uri'])
                    ) {
                        $path = $serverEnv['request_uri'];
                    }

                    $match = $app->webSocketRouter()->match($path);

                    if ($match === null) {
                        $s->disconnect($fd, 1000, 'No WebSocket route');

                        return;
                    }

                    $route = $match['route'];
                    $psr7  = SwooleRequestTranslator::toPsr7($req);
                    $ctx   = new LocalWebSocketContext($s, $fd, $psr7);
                    $table = $runtime->connections;

                    if ($table === null) {
                        $s->disconnect($fd, 1011, 'Server error');

                        return;
                    }

                    if ($route->mode === WebSocketRoute::MODE_HANDLER) {
                        $factory = $route->factory;

                        if ($factory === null) {
                            $s->disconnect($fd, 1011, 'Server error');

                            return;
                        }

                        /** @var WebSocketHandler $handler */
                        $handler = $factory($ctx);
                        $table->attachHandler($fd, $handler, $ctx);
                    } else {
                        $props    = $route->props;
                        $registry = $runtime->channels;

                        if ($props === null || $registry === null) {
                            $s->disconnect($fd, 1011, 'Server error');

                            return;
                        }

                        $keyFrom = $route->keyFrom ?? '';
                        $key     = $match['params'][$keyFrom] ?? '';

                        $actorName = ChannelActorNameResolver::resolve($key);
                        $actor     = $registry->resolveOrSpawn($actorName, $props);

                        $actor->tell(new ChannelConnectionOpened($fd, $ctx, $psr7));
                        $table->attachChannel($fd, $actor, $actorName, $ctx);
                    }
                } catch (Throwable $e) {
                    $config->logger->error('WebSocket Open failed', ['exception' => $e]);
                    $s->disconnect($fd, 1011, 'Server error');
                }
            });

            $server->on(
                'Message',
                static function (WebSocketServer $s, SwooleFrame $frame) use ($config, $runtime): void {
                    try {
                        $table = $runtime->connections;
    
                        if ($table === null) {
                            return;
                        }
    
                        $entry = $table->get((int) $frame->fd);
    
                        if ($entry === null) {
                            return;
                        }
    
                        $kind = (int) $frame->opcode === 2
                            ? WebSocketFrame::KIND_BINARY
                            : WebSocketFrame::KIND_TEXT;
                        $wsFrame = new WebSocketFrame($kind, (string) $frame->data);
    
                        if ($entry['handler'] !== null) {
                            $entry['handler']->onMessage($wsFrame);
                        } elseif ($entry['channelActor'] !== null) {
                            $entry['channelActor']->tell(new ChannelMessageReceived((int) $frame->fd, $wsFrame));
                        }
                    } catch (Throwable $e) {
                        $config->logger->error('WebSocket Message failed', ['exception' => $e]);
                    }
                },
            );

            $server->on('Close', static function (WebSocketServer $s, int $fd) use ($config, $runtime): void {
                try {
                    $table = $runtime->connections;

                    if ($table === null) {
                        return;
                    }

                    $entry = $table->get($fd);

                    if ($entry !== null) {
                        if ($entry['handler'] !== null) {
                            $entry['handler']->onClose(1000);
                        } elseif ($entry['channelActor'] !== null) {
                            $entry['channelActor']->tell(new ChannelConnectionClosed($fd, 1000));
                        }
                    }

                    $table->remove($fd);
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
                            'workerId'  => $workerId,
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
        $now    = microtime(true);
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
