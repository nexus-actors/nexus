<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Boot;

use Psr\Log\LoggerInterface;

/**
 * Main-process bootstrap. Runs exactly ONCE before SwooleWorkerServer::run().
 *
 * In worker (process) mode the Swoole coroutine hook is installed per-worker
 * by SwooleRuntime — installing it here would create the event loop too
 * early and prevent the server from starting. So this bootstrap is
 * deliberately light: sync logger + banner, nothing else.
 */
final readonly class Bootstrap
{
    private function __construct(public LoggerInterface $logger) {}

    public static function run(Config $config): self
    {
        $logger = StderrLogger::create('bootstrap');
        $logger->info('booting nexus-tictactoe', [
            'php' => PHP_VERSION,
            'pid' => getmypid(),
            'swoole' => phpversion('swoole'),
        ]);
        $logger->info('config resolved', [
            'host' => $config->http->host,
            'port' => $config->http->port,
            'workers' => $config->http->threads,
        ]);

        return new self($logger);
    }
}
