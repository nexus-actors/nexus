<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Thread;
use Swoole\Thread\ArrayList;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Throwable;

use function spl_object_id;

/**
 * @psalm-api
 *
 * Thread-mode HTTP runner using Swoole 6's native SWOOLE_THREAD server mode.
 *
 * Boots a single Swoole\Http\Server in the main thread; Swoole spawns N worker
 * threads that share the listening socket and dispatch incoming requests via
 * the kernel. Each worker thread runs the factory at WorkerStart to build a
 * per-thread ActorSystem + WorkerNode. WebSocket events are added in Phase 16.
 *
 * Cross-thread pool-singleton actor support is wired automatically: a
 * Thread\Map directory and one Thread\Queue per worker are allocated in the
 * main thread via the Server's init_arguments hook (the documented Swoole 6
 * mechanism for sharing thread-safe resources), then retrieved from each
 * worker thread via Swoole\Thread::getArguments(). The factory receives a
 * fully-wired WorkerNode whose hash ring routes spawn() calls across all
 * threads.
 *
 * The factory closure has signature:
 *   Closure(ActorSystem, WorkerNode): CompiledHttpApp
 *
 * Call this method from the main thread; it blocks until the server shuts
 * down (SIGTERM/SIGINT or explicit Server::shutdown()).
 */
final class SwooleThreadHttpServer
{
    /** @var array<int, CompiledHttpApp> */
    private static array $appsByServerId = [];

    /** @var array<int, ActorSystem> */
    private static array $systemsByServerId = [];

    /** @param Closure(ActorSystem, WorkerNode): CompiledHttpApp $factory */
    public static function run(SwooleThreadConfig $config, Closure $factory): void
    {
        $threads = $config->threads;
        $server = new Server($config->host, $config->port, SWOOLE_THREAD, SWOOLE_SOCK_TCP);

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

        $server->on('WorkerStart', static function (Server $s, int $workerId) use ($factory, $config): void {
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

                $system = ActorSystem::create("http-thread-{$workerId}", new SwooleRuntime());
                $node   = new WorkerNode(
                    $workerId,
                    $system,
                    new ThreadQueueTransport($queues, $workerId),
                    new ConsistentHashRing($totalThreads),
                    new ThreadMapDirectory($directory),
                );
                $node->start();

                $app = $factory($system, $node);

                $serverId                            = spl_object_id($s);
                self::$appsByServerId[$serverId]     = $app;
                self::$systemsByServerId[$serverId]  = $system;
            } catch (Throwable $e) {
                $config->logger->error('HTTP factory failed during WorkerStart', [
                    'exception' => $e,
                    'workerId'  => $workerId,
                ]);
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
                self::$systemsByServerId[$serverId],
            );
        });

        // WebSocket events added in Phase 16 (cross-thread broadcast support).

        // Swoole's SWOOLE_THREAD mode wires SIGTERM/SIGINT to Server::shutdown
        // natively. The $config->installSignalHandlers flag is retained for
        // API parity with the worker-mode config; a no-op here is correct.

        $server->start();
    }

    /**
     * Best-effort: pick the first registered server id (single-server is typical).
     */
    private static function resolveServerId(): int
    {
        foreach (self::$appsByServerId as $id => $_) {
            return $id;
        }

        return 0;
    }
}
