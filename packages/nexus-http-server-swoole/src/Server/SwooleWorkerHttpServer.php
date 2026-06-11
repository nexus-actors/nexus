<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Throwable;

use function array_key_first;
use function microtime;
use function spl_object_id;

/**
 * @psalm-api
 *
 * Worker-mode HTTP runner. Boots ext-swoole's master + N worker processes.
 * Each worker runs the factory to build a per-worker CompiledHttpApp at
 * WorkerStart, then serves Request events.
 *
 * Pool-singleton actors are NOT available in worker mode (no shared ring
 * across processes). Use thread mode for pool-singletons.
 */
final class SwooleWorkerHttpServer
{
    /** @var array<int, CompiledHttpApp> */
    private static array $appsByServerId = [];

    /** @var array<int, array{count:int, since:float}> */
    private static array $failureCounters = [];

    /** @var array<int, ActorSystem> */
    private static array $systemsByServerId = [];

    /** @param Closure(ActorSystem): CompiledHttpApp $factory */
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

                $serverId                            = spl_object_id($s);
                self::$appsByServerId[$serverId]     = $app;
                self::$systemsByServerId[$serverId]  = $system;
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
                // The Request event has no direct Server reference; in single-server
                // worker mode the app table has one entry per worker process. Pick the
                // first registered app.
                $firstKey = array_key_first(self::$appsByServerId);

                if ($firstKey === null) {
                    $res->status(503);
                    $res->end('Service not ready');

                    return;
                }

                $app  = self::$appsByServerId[$firstKey];
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

            unset(self::$appsByServerId[$serverId], self::$systemsByServerId[$serverId]);
        });

        if ($config->installSignalHandlers) {
            ShutdownSignalHandler::install($server, $config->logger);
        }

        $server->start();
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
