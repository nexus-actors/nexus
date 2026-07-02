<?php

/**
 * Standalone child-process entry script for SwooleThreadServer HTTP integration tests.
 *
 * SWOOLE_THREAD mode re-runs the entry script in every worker thread, so the
 * child process cannot be a phpunit re-entry — it would re-execute phpunit's
 * launcher on each thread spawn and fail. This script is launched via
 * Swoole\Process::exec(PHP_BINARY, [thisScript, host, port, threads]),
 * which replaces the child process image with a fresh PHP interpreter
 * running just this file.
 *
 * Args:
 *   $argv[1] host     (e.g. 127.0.0.1)
 *   $argv[2] port     (int)
 *   $argv[3] threads  (int)
 */

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/../../../../vendor/autoload.php';

/** @var string $host */
$host = $argv[1] ?? '127.0.0.1';
/** @var int $port */
$port = (int) ($argv[2] ?? 0);
/** @var int $threads */
$threads = (int) ($argv[3] ?? 1);

SwooleThreadServer::run(
    config: SwooleThreadConfig::bind($host, $port)
        ->threads($threads)
        ->installSignalHandlers(true),
    factory: static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        $app = HttpApplication::create($system);
        $app->get('/hello', static fn(): ResponseInterface => Response::ok());

        return $app->compile();
    },
);
