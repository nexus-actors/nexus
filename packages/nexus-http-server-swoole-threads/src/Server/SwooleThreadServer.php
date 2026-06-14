<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleServerEventBinder;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Server as HttpServer;
use Swoole\Thread;
use Swoole\Thread\ArrayList;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

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
                $config->logger->info('Thread starting', ['threadId' => $workerId]);

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
                    $config->logger->info('Thread started', [
                        'hasWebSocketRoutes' => $app->hasWebSocketRoutes(),
                        'threadId' => $workerId,
                    ]);
                } catch (Throwable $e) {
                    $config->logger->error('HTTP factory failed during WorkerStart', [
                        'exception' => $e,
                        'workerId' => $workerId,
                    ]);
                    SwooleServerEventBinder::recordFailureAndMaybeShutdown(
                        $s,
                        $runtime,
                        $config->logger,
                        'HTTP factory failed during thread boot 3 times in 5s — shutting down server.',
                    );
                }
            },
        );

        SwooleServerEventBinder::bindRequest($server, $runtime, $config->logger);

        if ($enableWebSocket) {
            assert($server instanceof WebSocketServer);
            SwooleServerEventBinder::bindWebSocket(
                $server,
                $runtime,
                static fn(WebSocketServer $s, int $fd, ServerRequestInterface $req): WebSocketContext => new ThreadAwareConnectionContext(
                    $s,
                    $fd,
                    $req,
                ),
                $config->logger,
            );
        }

        SwooleServerEventBinder::bindWorkerStop($server, $runtime, $config->shutdownTimeout, $config->logger);

        // Swoole SWOOLE_THREAD mode wires SIGTERM/SIGINT natively.
        // installSignalHandlers retained for API parity with worker mode — no-op here.

        $config->logger->info('SwooleThreadServer booting', [
            'enableWebSocket' => $config->enableWebSocket,
            'host' => $config->host,
            'port' => $config->port,
            'threads' => $config->threads,
        ]);
        $server->start();
    }
}
