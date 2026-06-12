<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Server\Swoole\Threads\WebSocket\Message\WebSocketFramePush;
use Monadial\Nexus\Http\Server\Swoole\Threads\WebSocket\ThreadAwareWebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ChannelActorNameResolver;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ConnectionTable;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelMessageReceived;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRouter;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as HttpServer;
use Swoole\Thread;
use Swoole\Thread\ArrayList;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

use function is_array;
use function is_string;
use function microtime;

use const WEBSOCKET_OPCODE_BINARY;
use const WEBSOCKET_OPCODE_PING;

/**
 * @psalm-api
 *
 * Thread-mode HTTP (and optional WebSocket) runner using Swoole 6's native
 * SWOOLE_THREAD server mode.
 *
 * Boots a single Swoole\Http\Server (or Swoole\WebSocket\Server when
 * WebSocket is enabled via SwooleThreadConfig::enableWebSocket(true)) in the
 * main thread; Swoole spawns N worker threads that share the listening socket
 * and dispatch incoming requests via the kernel. Each worker thread runs the
 * factory at WorkerStart to build a per-thread ActorSystem + WorkerNode and
 * (when WebSocket is enabled and the factory returns a SwooleCompiledHttpApp)
 * wires WebSocket events.
 *
 * Per-thread state lives on a single ThreadServerRuntime instance captured by
 * the event closures via `use` — no static arrays keyed by object id.
 *
 * Cross-thread pool-singleton actor support is wired automatically: a
 * Thread\Map directory and one Thread\Queue per worker are allocated in the
 * main thread via the Server's init_arguments hook, then retrieved from each
 * worker thread via Swoole\Thread::getArguments(). The factory receives a
 * fully-wired WorkerNode whose hash ring routes spawn() calls across all
 * threads.
 *
 * ## WebSocket model (v1) — only when enableWebSocket(true)
 *
 * Channel actors in v1 are **thread-local**: each thread keeps its own
 * ChannelActorRegistry, so a connection that lands on thread Y creates (or
 * reuses) the channel actor on thread Y. Two connections to the same channel
 * key that land on different threads will be served by two distinct actor
 * instances. This trades the single-actor-per-channel guarantee for a clean
 * serialization story (the `WebSocketContext` and Swoole `Request` carried in
 * `ChannelConnectionOpened` cannot cross threads via Thread\Queue, which uses
 * php_serialize internally).
 *
 * Cross-thread broadcast plumbing (`WebSocketFramePush` + per-thread router
 * actors) IS wired up so that a thread can push to fds owned by another
 * thread. Each thread spawns one well-known router actor at WorkerStart whose
 * name is deterministically salted to land on that specific thread; other
 * threads resolve a WorkerActorRef for each router via
 * `WorkerNode::actorFor()` and can tell `WebSocketFramePush` messages
 * directly. The router on the owning thread looks up the fd in its local
 * ConnectionTable and pushes via the local Swoole server.
 *
 * In v1, application code is responsible for invoking the cross-thread push
 * path explicitly (e.g. an application-level service that fans messages out
 * across threads). The `ThreadAwareWebSocketContext` short-circuits to a
 * direct local push when the calling thread owns the fd; it falls back to
 * the registered router senders otherwise. The channel-actor message path
 * stays thread-local until a future revision adds serialization-safe context
 * transport.
 *
 * The factory closure has signature:
 *   Closure(ActorSystem, WorkerNode): (CompiledHttpApp|SwooleCompiledHttpApp)
 *
 * Call this method from the main thread; it blocks until the server shuts
 * down (SIGTERM/SIGINT or explicit Server::shutdown()).
 */
final class SwooleThreadHttpServer
{
    private const string ROUTER_NAME_PREFIX = 'ws-thread-router';
    private const int ROUTER_NAME_MAX_SALT = 100_000;

    /** @param Closure(ActorSystem, WorkerNode): (CompiledHttpApp|SwooleCompiledHttpApp) $factory */
    public static function run(SwooleThreadConfig $config, Closure $factory): void
    {
        $threads         = $config->threads;
        $enableWebSocket = $config->enableWebSocket;
        $runtime         = new ThreadServerRuntime();
        $server          = $enableWebSocket
            ? new WebSocketServer($config->host, $config->port, SWOOLE_THREAD, SWOOLE_SOCK_TCP)
            : new HttpServer($config->host, $config->port, SWOOLE_THREAD, SWOOLE_SOCK_TCP);

        $settings = [
            'max_request'    => $config->maxRequest,
            'worker_num'     => $threads,
            /**
             * Thread\ArrayList stubs constrain offsetSet to ArrayAccess values;
             * Swoole 6 actually accepts any thread-safe type including Queue.
             *
             * @psalm-suppress InvalidArgument
             */
            'init_arguments' => static function () use ($threads): array {
                $directory = new Map();
                $queues    = new ArrayList();

                for ($i = 0; $i < $threads; $i++) {
                    $queues[] = new Queue();
                }

                return [$directory, $queues, $threads];
            },
        ];

        $server->set($settings);

        $server->on(
            'WorkerStart',
            static function (HttpServer|WebSocketServer $s, int $workerId) use ($factory, $config, $runtime, $enableWebSocket): void {
                try {
                    /** @var array{0: Map, 1: ArrayList, 2: int} $args */
                    $args         = Thread::getArguments();
                    $directory    = $args[0];
                    $queueList    = $args[1];
                    $totalThreads = $args[2];

                    /** @var array<int, Queue> $queues */
                    $queues = [];

                    for ($i = 0; $i < $totalThreads; $i++) {
                        /**
                         * Thread\ArrayList offsetGet's stubbed signature constrains the key,
                         * but the integer ListAccess is the documented API in Swoole 6.
                         *
                         * @psalm-suppress InvalidArgument
                         * @var Queue $q
                         */
                        $q          = $queueList[$i];
                        $queues[$i] = $q;
                    }

                    $ring   = new ConsistentHashRing($totalThreads);
                    $system = ActorSystem::create("http-thread-{$workerId}", new SwooleRuntime());
                    $node   = new WorkerNode(
                        $workerId,
                        $system,
                        new ThreadQueueTransport($queues, $workerId),
                        $ring,
                        new ThreadMapDirectory($directory),
                    );
                    $node->start();

                    $app = $factory($system, $node);

                    if ($enableWebSocket && $app instanceof SwooleCompiledHttpApp) {
                        self::assertNoChannelRoutes($app->webSocketRouter());
                    }

                    $runtime->system = $system;
                    $runtime->app    = $app;

                    if ($enableWebSocket) {
                        assert($s instanceof WebSocketServer);
                        $table           = new ConnectionTable();
                        $routerNames     = self::computeRouterNames($ring, $totalThreads);
                        $localRouterName = $routerNames[$workerId];

                        $node->spawn(
                            self::routerProps($table, $s),
                            $localRouterName,
                        );

                        $routerSenders = [];

                        foreach ($routerNames as $threadId => $name) {
                            if ($threadId === $workerId) {
                                // Same-thread short-circuit: dispatch in-process (also
                                // unused by ThreadAwareWebSocketContext, which has its
                                // own same-thread fast path; included for symmetry).
                                $routerSenders[$threadId] = static function (WebSocketFramePush $msg) use ($s, $table): void {
                                    self::dispatchFramePushLocally($s, $table, $msg);
                                };

                                continue;
                            }

                            $remoteRef = $node->actorFor('/user/' . $name);

                            if ($remoteRef === null) {
                                continue;
                            }

                            $routerSenders[$threadId] = static function (WebSocketFramePush $msg) use ($remoteRef): void {
                                $remoteRef->tell($msg);
                            };
                        }

                        $runtime->channels      = new ChannelActorRegistry($system);
                        $runtime->connections   = $table;
                        $runtime->threadId      = $workerId;
                        $runtime->routerSenders = $routerSenders;
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

                    $route    = $match['route'];
                    $psr7     = SwooleRequestTranslator::toPsr7($req);
                    $table    = $runtime->connections;
                    $threadId = $runtime->threadId;
                    $senders  = $runtime->routerSenders;

                    if ($table === null || $threadId === null) {
                        $s->disconnect($fd, 1011, 'Server error');

                        return;
                    }

                    // The fd owner is the thread that accepted the upgrade — i.e.
                    // the thread currently running this Open event. The channel
                    // actor (v1: thread-local) runs on the same thread, so the
                    // context's same-thread fast path is hit on every push.
                    $ctx = new ThreadAwareWebSocketContext($s, $threadId, $threadId, $fd, $psr7, $senders);

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

        // Swoole's SWOOLE_THREAD mode wires SIGTERM/SIGINT to Server::shutdown
        // natively. The $config->installSignalHandlers flag is retained for
        // API parity with the worker-mode config; a no-op here is correct.

        $server->start();
    }

    /**
     * Deterministically compute a router actor name for each thread such
     * that the ring assigns the name to that specific thread.
     *
     * @return array<int, string> threadId -> actor name
     */
    public static function computeRouterNames(ConsistentHashRing $ring, int $totalThreads): array
    {
        $names = [];

        for ($threadId = 0; $threadId < $totalThreads; $threadId++) {
            for ($salt = 0; $salt < self::ROUTER_NAME_MAX_SALT; $salt++) {
                $candidate = self::ROUTER_NAME_PREFIX . "-{$threadId}-s{$salt}";

                if ($ring->getWorker($candidate) === $threadId) {
                    $names[$threadId] = $candidate;

                    break;
                }
            }

            if (!isset($names[$threadId])) {
                // Fallback to the prefix+thread name even if the ring would not
                // map it to $threadId. With 150 virtual nodes per worker and
                // any reasonable thread count, the search above succeeds in
                // practice, so this branch is defensive only.
                $names[$threadId] = self::ROUTER_NAME_PREFIX . "-{$threadId}";
            }
        }

        return $names;
    }

    /**
     * Thread-mode v1 does not support channel-actor routes — the
     * ChannelConnectionOpened envelope carries a Swoole Request + a
     * WebSocketContext bound to a Swoole server handle, neither of which
     * round-trip through Thread\Queue (php_serialize). Accepting such routes
     * would silently serve them with thread-local semantics, which violates
     * the user's pub/sub expectations under load distribution.
     *
     * Fail fast at boot so the misconfiguration surfaces in the worker-boot
     * circuit breaker rather than at the first WebSocket upgrade.
     */
    public static function assertNoChannelRoutes(WebSocketRouter $router): void
    {
        foreach ($router->routes() as $route) {
            if ($route->mode === WebSocketRoute::MODE_CHANNEL) {
                throw new RuntimeException(
                    "WebSocket channel-actor routes are not supported in thread mode "
                    . "(route '{$route->path}'). Use handler-mode WebSocket here, "
                    . 'or switch to nexus-http-server-swoole (worker mode) for channel actors.',
                );
            }
        }
    }

    /**
     * @return Props<object>
     */
    private static function routerProps(ConnectionTable $table, WebSocketServer $server): Props
    {
        /**
         * @psalm-suppress InvalidArgument
         *   Behavior::receive's @template U of object can't be inferred from
         *   the broad `object` param type in diff-mode runs of Psalm. The
         *   router only ever receives WebSocketFramePush; the instanceof
         *   guard makes this safe at runtime.
         */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use ($table, $server): Behavior {
                if ($msg instanceof WebSocketFramePush) {
                    self::dispatchFramePushLocally($server, $table, $msg);

                    return Behavior::same();
                }

                return Behavior::unhandled();
            },
        );

        /** @var Props<object> */
        return Props::fromBehavior($behavior);
    }

    private static function recordFailureAndMaybeShutdown(
        HttpServer|WebSocketServer $server,
        SwooleThreadConfig $config,
        ThreadServerRuntime $runtime,
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
            $config->logger->error('HTTP factory failed during thread boot 3 times in 5s — shutting down server.');
            $server->shutdown();
        }
    }

    private static function dispatchFramePushLocally(
        WebSocketServer $server,
        ConnectionTable $table,
        WebSocketFramePush $msg,
    ): void {
        if (!$table->has($msg->fd)) {
            // fd is not on this thread (or was closed mid-flight); silently drop.
            return;
        }

        match ($msg->kind) {
            WebSocketFramePush::KIND_BINARY => $server->push($msg->fd, $msg->payload, WEBSOCKET_OPCODE_BINARY),
            WebSocketFramePush::KIND_PING   => $server->push($msg->fd, '', WEBSOCKET_OPCODE_PING),
            WebSocketFramePush::KIND_CLOSE  => $server->disconnect($msg->fd, $msg->closeCode, $msg->closeReason),
            default                         => $server->push($msg->fd, $msg->payload),
        };
    }
}
