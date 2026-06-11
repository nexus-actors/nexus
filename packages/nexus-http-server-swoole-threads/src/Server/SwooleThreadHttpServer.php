<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Server;
use Throwable;

/**
 * @psalm-api
 *
 * Per-thread HTTP+WebSocket runner. Called from WorkerPoolApp::configure()
 * inside each thread. Reuses the worker-mode bridge + event-handler logic;
 * the actor system is the one already owned by WorkerNode.
 *
 * Binds a Swoole\WebSocket\Server with SO_REUSEPORT so all threads share
 * the same listening address; the kernel load-balances connections.
 */
final class SwooleThreadHttpServer
{
    /** @param Closure(ActorSystem, WorkerNode): CompiledHttpApp $factory */
    public static function onThread(WorkerNode $node, SwooleThreadConfig $config, Closure $factory): void
    {
        $system = $node->system();
        $app    = $factory($system, $node);

        $server = new Server($config->host, $config->port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
        $settings = [
            'enable_reuse_port'  => true,
            'max_request'        => $config->maxRequest,
            'open_http_protocol' => true,
            'worker_num'         => 0,
        ];
        $server->set($settings);

        $server->on('Request', static function (Request $req, Response $res) use ($app, $config): void {
            try {
                $psr7 = SwooleRequestTranslator::toPsr7($req);
                SwooleResponseWriter::write($app->handle($psr7), $res);
            } catch (Throwable $e) {
                $config->logger->error('Request failed', ['exception' => $e]);

                if ($res->isWritable()) {
                    $res->status(500);
                    $res->end('Internal Server Error');
                }
            }
        });

        // WebSocket events added in Phase 16 (cross-thread broadcast support).

        $server->start();
    }
}
