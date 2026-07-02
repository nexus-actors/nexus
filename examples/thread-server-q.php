<?php

declare(strict_types=1);

/**
 * Prototype #2: dedicated writer thread + shared Swoole\Thread\Queue.
 *
 * Each worker thread's NexusLogger uses ThreadQueueHandler, which pushes
 * formatted log lines onto a shared Swoole\Thread\Queue. A separate
 * Swoole\Thread (examples/logger-writer.php) drains the queue and writes
 * to a single file with no locks, no per-write open/close, hot fd.
 *
 *   docker compose exec php-swoole php examples/thread-server-q.php
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
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Mdc;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Logger\Processor\CallerInfoProcessor;
use Monadial\Nexus\Logger\Swoole\ThreadQueueHandler;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Queue;

$logQueue = new Queue();
$shutdown = new Atomic(0);
$logFile = '/tmp/nexus-async.log';

@unlink($logFile);

$writer = new Thread(__DIR__ . '/logger-writer.php', $logQueue, $logFile, $shutdown);

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->shutdownTimeout(Duration::seconds(5))
        ->withLogQueue($logQueue),
    static function (ActorSystem $system, WorkerNode $node) use ($logQueue): CompiledApplication {
        $host = gethostname();
        $pid = getmypid();
        Mdc::putStatic('host', $host === false ? 'unknown' : $host);
        Mdc::putStatic('pid', $pid === false ? 0 : $pid);
        Mdc::putStatic('threadId', $node->workerId());
        Mdc::putStatic('service', 'thread-server-q');

        $logger = NexusLogger::create($system, "thread-{$node->workerId()}")
            ->minLevel(Level::Debug)
            ->processor(CallerInfoProcessor::onlyFor(Level::Debug, Level::Error, Level::Critical))
            ->handler(new ThreadQueueHandler($logQueue, new LineFormatter()))
            ->build();

        $logger->info('thread up');

        $app = HttpApplication::create($system);

        $app->get('/', static function () use ($logger, $node): mixed {
            Mdc::put('requestId', bin2hex(random_bytes(4)));
            Mdc::put('route', 'GET /');
            $logger->debug('handling root');

            return JsonResponse::ok([
                'links' => [
                    ['href' => '/', 'rel' => 'self'],
                    ['href' => '/health', 'rel' => 'health'],
                    ['href' => '/hello/{name}', 'rel' => 'greeting'],
                ],
                'name' => 'nexus-http-server-swoole-threads example (queue)',
                'pid' => getmypid(),
                'tid' => $node->workerId(),
            ]);
        });

        $app->get('/health', static fn(): mixed => Response::ok());

        $app->get('/hello/{name}', static function (ServerRequestInterface $req) use ($logger, $node): mixed {
            $name = (string) $req->getAttribute('name');
            Mdc::put('requestId', bin2hex(random_bytes(4)));
            Mdc::put('route', 'GET /hello/{name}');
            $logger->info('greeting {name}', ['name' => $name]);

            return JsonResponse::ok(['greeting' => 'Hello, ' . $name . '!', 'tid' => $node->workerId()]);
        });

        return $app->compile();
    },
);

$shutdown->set(1);
$writer->join();
