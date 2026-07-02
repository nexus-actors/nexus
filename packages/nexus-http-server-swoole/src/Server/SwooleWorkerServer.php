<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleServerEventBinder;
use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Coroutine;
use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

use const SWOOLE_HOOK_ALL;
use const SWOOLE_HOOK_STDIO;

/**
 * @psalm-api
 *
 * Worker-mode HTTP+WebSocket runner. Builds a per-worker
 * CompiledApplication via the user-supplied factory; wires Swoole
 * Request to handle(), and (if enableWebSocket) Open/Message/Close
 * to the dispatcher via SwooleServerEventBinder.
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

        // Precedence, low to high: framework default -> user overrides -> framework
        // core keys. `websocket_compression` is a DEFAULT (overridable), so it goes
        // in first and the user spread can flip it; the core keys are assigned after
        // the spread so they always win. (Assigning core keys one by one rather than
        // in a second literal keeps the alphabetical-array sniff from reordering them
        // back before the spread, which is what silently made this non-overridable.)
        //
        // Why default off: Swoole advertises `permessage-deflate`, and on a build
        // without zlib (our official image) every outbound frame is silently dropped
        // with `FrameObject::pack(): Unable to compress`. A zlib-enabled deploy opts
        // back in via `SwooleWorkerConfig::withSwooleSetting(['websocket_compression' => true])`.
        $settings = ['websocket_compression' => false];
        $settings = [...$settings, ...$config->swooleSettings];
        $settings['dispatch_mode'] = $config->dispatchMode;
        $settings['max_conn'] = $config->maxConn;
        $settings['max_request'] = $config->maxRequest;
        $settings['reactor_num'] = $config->reactorThreads;
        $settings['worker_num'] = $config->workers;

        if ($config->logFile !== '') {
            $settings['log_file'] = $config->logFile;
        }

        $server->set($settings);

        $server->on(
            'WorkerStart',
            static function (HttpServer|WebSocketServer $s, int $workerId) use ($factory, $config, $runtime): void {
                $config->logger->info('Worker starting', ['workerId' => $workerId]);

                // Install Swoole's coroutine hook AFTER the master fork but BEFORE
                // any actor-side I/O. Doctrine PDO, sockets, and file operations
                // inside the actor system must yield cooperatively rather than
                // block the worker's reactor thread. Without this, a single
                // blocking PDO call from an actor stalls the entire worker and
                // Swoole eventually flags a deadlock on the reactor timeout.
                // Setting it here (per-worker) sidesteps the "event-loop already
                // created" error that installing on the main thread would cause.
                // Hook everything EXCEPT stdio. Hooking stdio makes fwrite/echo
                // yield the coroutine, which reorders log output vs actor work
                // and confuses debug tracing. All other subsystems (PDO, curl,
                // sockets, sleep, files) yield cooperatively so a blocking call
                // in an actor doesn't stall the worker's reactor.
                Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO]);

                try {
                    // Passing the config logger to ActorSystem::create means every
                    // `$ctx->log()` call inside an actor routes to the same sink
                    // the framework uses. If the user wants a different logger
                    // per actor system, they can wrap the factory.
                    $system = ActorSystem::create(
                        "http-worker-{$workerId}",
                        new SwooleRuntime(),
                        logger: $config->logger,
                    );
                    $app = $factory($system);
                    $runtime->system = $system;
                    $runtime->app = $app;

                    $config->logger->info('Worker started', [
                        'hasWebSocketRoutes' => $app->hasWebSocketRoutes(),
                        'workerId' => $workerId,
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
                        'HTTP factory failed during worker boot 3 times in 5s — shutting down master.',
                    );
                }
            },
        );

        SwooleServerEventBinder::bindRequest($server, $runtime, $config->logger);

        if ($config->enableWebSocket) {
            assert($server instanceof WebSocketServer);
            SwooleServerEventBinder::bindWebSocket(
                $server,
                $runtime,
                static fn(WebSocketServer $s, int $fd, ServerRequestInterface $req): WebSocketContext => new SwooleConnectionContext(
                    $s,
                    $fd,
                    $req,
                ),
                $config->logger,
            );
        }

        SwooleServerEventBinder::bindWorkerStop($server, $runtime, $config->shutdownTimeout, $config->logger);

        if ($config->installSignalHandlers) {
            ShutdownSignalHandler::install($server, $config->logger);
            $config->logger->debug('SwooleWorkerServer: SIGTERM/SIGINT signal handlers installed');
        }

        $config->logger->info('SwooleWorkerServer booting', [
            'enableWebSocket' => $config->enableWebSocket,
            'host' => $config->host,
            'port' => $config->port,
            'workers' => $config->workers,
        ]);
        $server->start();
    }
}
