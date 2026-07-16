<?php

declare(strict_types=1);

/**
 * Wallet-app entry point — composition root.
 *
 * Reads {@see WalletConfig::fromEnv} once, runs the main-thread
 * {@see WalletBootstrap::run} (logger + Swoole coroutine-hook
 * install), then hands a per-worker factory ({@see WalletApp::factory})
 * to {@see SwooleThreadServer::run}. Each worker thread builds its own
 * ActorSystem, Doctrine pools, and HttpApplication.
 *
 * The actual wiring lives under `src/Boot` and `src/Http`. This file is
 * intentionally short so the shape of the boot — config → bootstrap →
 * server.run — is the first thing a reader sees.
 */
// Standalone example install, or monorepo checkout — pick whichever exists.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
}

require_once $autoload;

use Monadial\Nexus\Example\Wallet\Boot\WalletApp;
use Monadial\Nexus\Example\Wallet\Boot\WalletBootstrap;
use Monadial\Nexus\Example\Wallet\Boot\WalletConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Runtime\Duration;

$config = WalletConfig::fromEnv();
$boot = WalletBootstrap::run($config);

try {
    SwooleThreadServer::run(
        SwooleThreadConfig::bind($config->http->host, $config->http->port)
            ->threads($config->http->threads)
            ->shutdownTimeout(Duration::seconds(5)),
        WalletApp::factory($config),
    );
} catch (Throwable $e) {
    $boot->logger->critical('server crashed', [
        'error' => $e::class . ': ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);
}
