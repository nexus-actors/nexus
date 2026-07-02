<?php

declare(strict_types=1);

/**
 * Same as thread-server.php BUT with all logging disabled — NullLogger
 * everywhere, no Mdc::put() calls, no logger->info() calls. Used to
 * measure the overhead of the async logger + MDC instrumentation by
 * comparing wrk results against the logging variant.
 *
 * @psalm-suppress all
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Http\Message\ServerRequestInterface;

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->shutdownTimeout(Duration::seconds(5)),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        $app = HttpApplication::create($system);

        $app->get('/', static fn(): mixed => JsonResponse::ok([
            'links' => [
                ['href' => '/', 'rel' => 'self'],
                ['href' => '/health', 'rel' => 'health'],
                ['href' => '/hello/{name}', 'rel' => 'greeting'],
            ],
            'name' => 'nexus-http-server-swoole-threads example (no-log)',
            'pid' => getmypid(),
            'tid' => $node->workerId(),
        ]));

        $app->get('/health', static fn(): mixed => Response::ok());

        $app->get('/hello/{name}', static fn(ServerRequestInterface $req): mixed => JsonResponse::ok([
            'greeting' => 'Hello, ' . (string) $req->getAttribute('name') . '!',
            'tid' => $node->workerId(),
        ]));

        return $app->compile();
    },
);
