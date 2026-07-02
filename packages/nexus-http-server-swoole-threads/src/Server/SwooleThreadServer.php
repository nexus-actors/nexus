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
use Swoole\Coroutine;
use Swoole\Http\Server as HttpServer;
use Swoole\Thread;
use Swoole\Thread\ArrayList;
use Swoole\Thread\Atomic;
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

        // Shutdown signal flipped from the main thread's BeforeShutdown event.
        // Each worker thread spawns a watchdog coroutine that polls this
        // atomic and triggers graceful teardown when set — necessary because
        // Swoole's WorkerStop hook fires AFTER the reactor exit timeout in
        // SWOOLE_THREAD mode, by which point the deadlock detector has
        // already flagged blocked coroutines. Built in the main thread so
        // BeforeShutdown can write to it; shared into worker threads via
        // init_arguments.
        $shutdownSignal = new Atomic(0);

        /**
         * @psalm-suppress InvalidArgument Thread\ArrayList stubs constrain
         * offsetSet to ArrayAccess values; Swoole 6 accepts any thread-safe
         * type including Queue.
         */
        $initArguments = static function () use ($threads, $config, $shutdownSignal): array {
            $directory = new Map();
            $queues = new ArrayList();

            for ($i = 0; $i < $threads; $i++) {
                $queues[] = new Queue();
            }

            return [$directory, $queues, $threads, $config->logQueue, $shutdownSignal];
        };

        // Precedence, low to high: framework default -> user overrides -> framework
        // core keys. `websocket_compression` defaults off (see SwooleWorkerServer for
        // the zlib rationale) but stays overridable via `withSwooleSetting()`; the
        // core keys are assigned after the spread so they always win. Assigning them
        // individually keeps the alphabetical-array sniff from reordering the default
        // back before the spread and silently making it non-overridable.
        $settings = ['websocket_compression' => false];
        $settings = [...$settings, ...$config->swooleSettings];
        $settings['init_arguments'] = $initArguments;
        $settings['max_request'] = $config->maxRequest;
        $settings['worker_num'] = $threads;

        $server->set($settings);

        $server->on(
            'WorkerStart',
            static function (HttpServer|WebSocketServer $s, int $workerId) use ($factory, $config, $runtime, $enableWebSocket): void {
                $config->logger->info('Thread starting', ['threadId' => $workerId]);

                try {
                    /** @var array{0: Map, 1: ArrayList, 2: int, 3: mixed, 4: Atomic} $args */
                    $args = Thread::getArguments();
                    $directory = $args[0];
                    $queueList = $args[1];
                    $totalThreads = $args[2];
                    $shutdownSignal = $args[4];

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
                    $transport = new ThreadQueueTransport($queues, $workerId);
                    $node = new WorkerNode(
                        $workerId,
                        $system,
                        $transport,
                        $ring,
                        new ThreadMapDirectory($directory),
                    );
                    $node->start();

                    $app = $factory($system, $node);
                    $runtime->transport = $transport;

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

                    // Watchdog: polls the shared shutdown atomic and triggers
                    // graceful teardown the moment BeforeShutdown is fired in
                    // the main thread. Doing this here (not in WorkerStop) is
                    // mandatory: Swoole 6 thread mode fires WorkerStop AFTER
                    // the worker reactor exit timeout, by which point the
                    // deadlock detector has already screamed.
                    $shutdownTimeout = $config->shutdownTimeout;
                    $logger = $config->logger;
                    Coroutine::create(static function () use (
                        $shutdownSignal,
                        $system,
                        $transport,
                        $runtime,
                        $shutdownTimeout,
                        $logger,
                        $workerId,
                    ): void {
                        while ($shutdownSignal->get() === 0) {
                            Coroutine::sleep(0.05);
                        }

                        $logger->info('Worker shutdown signal received', ['workerId' => $workerId]);
                        $transport->stop();

                        try {
                            $system->shutdown($shutdownTimeout);
                            $logger->info('Worker ActorSystem shutdown complete', ['workerId' => $workerId]);
                        } catch (Throwable $e) {
                            $logger->error('System shutdown failed in watchdog', [
                                'exception' => $e,
                                'workerId' => $workerId,
                            ]);
                        }

                        $runtime->reset();
                    });
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

        // BeforeShutdown fires once in the main thread when SIGTERM/SIGINT
        // arrives, BEFORE worker threads begin tearing down. Setting the
        // shared atomic here wakes the per-worker watchdog coroutines so
        // they can run our graceful-shutdown sequence within Swoole's
        // worker-exit window.
        $logger = $config->logger;
        $server->on('BeforeShutdown', static function () use ($shutdownSignal, $logger): void {
            $logger->info('Server BeforeShutdown — signalling worker watchdogs');
            $shutdownSignal->set(1);
        });

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
