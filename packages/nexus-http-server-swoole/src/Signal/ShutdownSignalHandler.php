<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Signal;

use Psr\Log\LoggerInterface;
use Swoole\Http\Server;
use Swoole\Process;

use const SIGINT;
use const SIGTERM;

/**
 * @psalm-api
 *
 * Installs SIGTERM/SIGINT handlers that call $server->shutdown().
 * Idempotent — Swoole replaces previous handlers for the same signal.
 */
final class ShutdownSignalHandler
{
    public static function install(Server $server, LoggerInterface $logger): void
    {
        $handler = static function (int $signal) use ($server, $logger): void {
            $logger->info('Received shutdown signal', ['signal' => $signal]);
            $server->shutdown();
        };

        Process::signal(SIGTERM, $handler);
        Process::signal(SIGINT, $handler);
    }
}
