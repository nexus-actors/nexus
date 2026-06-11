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
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server;
use Throwable;

use function array_key_first;
use function is_array;
use function is_string;
use function microtime;
use function spl_object_id;

/**
 * @psalm-api
 *
 * Worker-mode HTTP + WebSocket runner. Boots ext-swoole's master + N worker
 * processes. Each worker runs the factory at WorkerStart to build a per-worker
 * CompiledHttpApp (or SwooleCompiledHttpApp for WebSocket support).
 *
 * Channel-actor WebSocket routes spawn the channel actor as WorkerLocal —
 * no cross-worker sharing. See spec for thread-mode alternative.
 */
final class SwooleWorkerHttpServer
{
    /** @var array<int, CompiledHttpApp|SwooleCompiledHttpApp> */
    private static array $appsByServerId = [];

    /** @var array<int, ChannelActorRegistry> */
    private static array $channelsByServerId = [];

    /** @var array<int, ConnectionTable> */
    private static array $connectionsByServerId = [];

    /** @var array<int, array{count:int, since:float}> */
    private static array $failureCounters = [];

    /** @var array<int, ActorSystem> */
    private static array $systemsByServerId = [];

    /** @param Closure(ActorSystem): (CompiledHttpApp|SwooleCompiledHttpApp) $factory */
    public static function run(SwooleWorkerConfig $config, Closure $factory): void
    {
        $server = new Server($config->host, $config->port);

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

        $server->on('WorkerStart', static function (Server $s, int $workerId) use ($factory, $config): void {
            try {
                $system = ActorSystem::create("http-worker-{$workerId}", new SwooleRuntime());
                $app    = $factory($system);

                $serverId                                = spl_object_id($s);
                self::$appsByServerId[$serverId]         = $app;
                self::$systemsByServerId[$serverId]      = $system;
                self::$connectionsByServerId[$serverId]  = new ConnectionTable();
                self::$channelsByServerId[$serverId]     = new ChannelActorRegistry($system);
            } catch (Throwable $e) {
                $config->logger->error('HTTP factory failed during WorkerStart', [
                    'exception' => $e,
                    'workerId'  => $workerId,
                ]);
                self::recordFailureAndMaybeShutdown($s, $config);
            }
        });

        $server->on('Request', static function (Request $req, Response $res) use ($config): void {
            try {
                $serverId = self::resolveServerId();
                $app      = self::$appsByServerId[$serverId] ?? null;

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

        $server->on('Open', static function (Server $s, Request $req) use ($config): void {
            $fd = (int) $req->fd;

            try {
                $serverId = spl_object_id($s);
                $app      = self::$appsByServerId[$serverId] ?? null;

                if (!$app instanceof SwooleCompiledHttpApp) {
                    return;
                }

                /** @var mixed $serverEnv */
                $serverEnv = $req->server;
                $path      = '/';

                if (is_array($serverEnv) && isset($serverEnv['request_uri']) && is_string($serverEnv['request_uri'])) {
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
                $table = self::$connectionsByServerId[$serverId] ?? null;

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
                    $registry = self::$channelsByServerId[$serverId] ?? null;

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

        $server->on('Message', static function (Server $s, SwooleFrame $frame) use ($config): void {
            try {
                $serverId = spl_object_id($s);
                $table    = self::$connectionsByServerId[$serverId] ?? null;

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
        });

        $server->on('Close', static function (Server $s, int $fd) use ($config): void {
            try {
                $serverId = spl_object_id($s);
                $table    = self::$connectionsByServerId[$serverId] ?? null;

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

        $server->on('WorkerStop', static function (Server $s, int $workerId) use ($config): void {
            $serverId = spl_object_id($s);
            $system   = self::$systemsByServerId[$serverId] ?? null;

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

            unset(
                self::$appsByServerId[$serverId],
                self::$channelsByServerId[$serverId],
                self::$connectionsByServerId[$serverId],
                self::$systemsByServerId[$serverId],
            );
        });

        if ($config->installSignalHandlers) {
            ShutdownSignalHandler::install($server, $config->logger);
        }

        $server->start();
    }

    /**
     * Best-effort: pick the first registered server id (single-server is typical).
     * Multi-server-per-process is unsupported.
     */
    private static function resolveServerId(): int
    {
        $first = array_key_first(self::$appsByServerId);

        return $first ?? 0;
    }

    private static function recordFailureAndMaybeShutdown(Server $server, SwooleWorkerConfig $config): void
    {
        $serverId = spl_object_id($server);
        $now      = microtime(true);
        $bucket   = self::$failureCounters[$serverId] ?? ['count' => 0, 'since' => $now];

        if ($now - $bucket['since'] > 5.0) {
            $bucket = ['count' => 1, 'since' => $now];
        } else {
            $bucket['count']++;
        }

        self::$failureCounters[$serverId] = $bucket;

        if ($bucket['count'] >= 3) {
            $config->logger->error('HTTP factory failed during worker boot 3 times in 5s — shutting down master.');
            $server->shutdown();
        }
    }
}
