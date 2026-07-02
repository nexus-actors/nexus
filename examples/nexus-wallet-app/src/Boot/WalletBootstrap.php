<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Psr\Log\LoggerInterface;

/**
 * Main-thread bootstrap. Runs exactly ONCE, before SwooleThreadServer::run().
 *
 * Responsibilities:
 *  - Build the synchronous stderr logger used during boot.
 *  - Install Swoole's coroutine hooks via DoctrineBootstrap. This must
 *    happen on the main thread; child threads silently no-op the hook
 *    install.
 *  - Log a one-line "config resolved" banner so `docker compose logs`
 *    shows the effective HTTP/port settings.
 */
final readonly class WalletBootstrap
{
    private function __construct(public LoggerInterface $logger) {}

    public static function run(WalletConfig $config): self
    {
        $logger = StderrLogger::create('bootstrap');
        $logger->info('booting nexus-wallet-app', [
            'php' => PHP_VERSION,
            'pid' => getmypid(),
            'swoole' => phpversion('swoole'),
        ]);
        $logger->info('config resolved', [
            'host' => $config->http->host,
            'port' => $config->http->port,
            'threads' => $config->http->threads,
        ]);

        // SWOOLE_HOOK_ALL must be installed on the main thread BEFORE
        // any worker is spawned — Swoole rejects the hook in child
        // threads ("can only set on the main thread"). Once installed
        // here, every worker thread inherits the hook and PDO/socket
        // calls suspend the coroutine on I/O instead of blocking it.
        DoctrineBootstrap::enable();

        return new self($logger);
    }
}
