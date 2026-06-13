<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Nyholm\Psr7\ServerRequest;
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

use function microtime;

/**
 * @psalm-api
 *
 * Thread-mode HTTP+WebSocket runner using Swoole 6's native SWOOLE_THREAD
 * server. Per-thread ActorSystem + WorkerNode; shared Thread\Map + queues
 * allocated in init_arguments. WebSocket support is conditional on
 * $config->enableWebSocket and $app instanceof CompiledWsApplication.
 * Channel-mode routes are rejected at boot.
 */
final class SwooleThreadServer
{
    /** @param Closure(ActorSystem, WorkerNode): CompiledApplication $factory */
    public static function run(SwooleThreadConfig $config, Closure $factory): void
    {
        $threads = $config->threads;
        $enableWebSocket = $config->enableWebSocket;
        $runtime = new ThreadServerRuntime();
        $server = $enableWebSocket
            ? new WebSocketServer($config->host, $config->port, SWOOLE_THREAD, SWOOLE_SOCK_TCP)
            : new HttpServer($config->host, $config->port, SWOOLE_THREAD, SWOOLE_SOCK_TCP);

        $server->set([
            'max_request' => $config->maxRequest,
            'worker_num' => $threads,
            /**
             * Thread\ArrayList stubs constrain offsetSet to ArrayAccess values;
             * Swoole 6 actually accepts any thread-safe type including Queue.
             *
             * @psalm-suppress InvalidArgument
             */
            'init_arguments' => static function () use ($threads): array {
                $directory = new Map();
                $queues = new ArrayList();

                for ($i = 0; $i < $threads; $i++) {
                    $queues[] = new Queue();
                }

                return [$directory, $queues, $threads];
            },
        ]);

        $server->on(
            'WorkerStart',
            static function (HttpServer|WebSocketServer $s, int $workerId) use ($factory, $config, $runtime, $enableWebSocket): void {
                try {
                    /** @var array{0: Map, 1: ArrayList, 2: int} $args */
                    $args = Thread::getArguments();
                    $directory = $args[0];
                    $queueList = $args[1];
                    $totalThreads = $args[2];

                    /** @var array<int, Queue> $queues */
                    $queues = [];

                    for ($i = 0; $i < $totalThreads; $i++) {
                        /**
                         * @psalm-suppress InvalidArgument
                         * @var Queue $q
                         */
                        $q = $queueList[$i];
                        $queues[$i] = $q;
                    }

                    $ring = new ConsistentHashRing($totalThreads);
                    $system = ActorSystem::create("http-thread-{$workerId}", new SwooleRuntime());
                    $node = new WorkerNode(
                        $workerId,
                        $system,
                        new ThreadQueueTransport($queues, $workerId),
                        $ring,
                        new ThreadMapDirectory($directory),
                    );
                    $node->start();

                    $app = $factory($system, $node);

                    if ($enableWebSocket && $app instanceof CompiledWsApplication) {
                        // Channel routes rejected at boot — silent degradation would violate
                        // pub/sub guarantees in thread mode.
                        $app->webSocketRouter()->assertNoChannelRoutes();
                    }

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
                    $ctx = new ThreadAwareConnectionContext($s, (int) $req->fd, $psr7);
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
                        $ctx = new ThreadAwareConnectionContext($s, (int) $frame->fd, new ServerRequest('GET', '/'));
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

                    $ctx = new ThreadAwareConnectionContext($s, $fd, new ServerRequest('GET', '/'));
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

        // Swoole SWOOLE_THREAD mode wires SIGTERM/SIGINT natively.
        // installSignalHandlers retained for API parity with worker mode — no-op here.

        $server->start();
    }

    private static function recordFailureAndMaybeShutdown(
        HttpServer|WebSocketServer $server,
        SwooleThreadConfig $config,
        ThreadServerRuntime $runtime,
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
            $config->logger->error('HTTP factory failed during thread boot 3 times in 5s — shutting down server.');
            $server->shutdown();
        }
    }
}
