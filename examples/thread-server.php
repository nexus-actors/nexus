<?php

declare(strict_types=1);

/**
 * Thread-mode HTTP server example.
 *
 * Boots a Swoole SWOOLE_THREAD HTTP server on 0.0.0.0:8080 with 2 worker
 * threads. Each thread gets its own ActorSystem + WorkerNode + NexusLogger
 * (async, actor-backed, writing to STDOUT in line format).
 *
 * Run via:
 *   docker compose exec php-swoole php examples/thread-server.php
 *
 * Then in another terminal:
 *   curl http://127.0.0.1:8080/
 *   curl http://127.0.0.1:8080/health
 *   curl http://127.0.0.1:8080/hello/world
 *
 * Press Ctrl+C to stop (or send SIGTERM/SIGINT).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Http\Message\ServerRequestInterface;

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(2)
        ->shutdownTimeout(Duration::seconds(5)),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        $logger = NexusLogger::create($system, "thread-{$node->workerId()}")
            ->minLevel(Level::Debug)
            ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
            ->build();

        $logger->info('thread up', ['tid' => $node->workerId(), 'pid' => getmypid()]);

        $app = HttpApplication::create($system);

        $app->get('/', static function () use ($logger, $node): mixed {
            $logger->debug('GET /', ['tid' => $node->workerId()]);

            return JsonResponse::ok([
                'name' => 'nexus-http-server-swoole-threads example',
                'links' => [
                    ['href' => '/', 'rel' => 'self'],
                    ['href' => '/health', 'rel' => 'health'],
                    ['href' => '/hello/{name}', 'rel' => 'greeting'],
                ],
                'pid' => getmypid(),
                'tid' => $node->workerId(),
            ]);
        });

        $app->get('/health', static fn(): mixed => Response::ok());

        $app->get('/hello/{name}', static function (ServerRequestInterface $req) use ($logger, $node): mixed {
            $name = (string) $req->getAttribute('name');
            $logger->info('greeting {name}', ['name' => $name, 'tid' => $node->workerId()]);

            return JsonResponse::ok([
                'greeting' => 'Hello, ' . $name . '!',
                'tid' => $node->workerId(),
            ]);
        });

        return $app->compile();
    },
);
