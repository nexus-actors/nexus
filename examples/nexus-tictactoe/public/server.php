<?php

declare(strict_types=1);

/**
 * Tic-tac-toe entry point — composition root.
 *
 * Uses {@see SwooleWorkerServer} (process-mode) rather than the thread
 * server because the `WebSocketChannelActor` needs shared memory to fan
 * out to all attached sockets — thread mode explicitly rejects channel
 * routes since cross-thread pub/sub is out of scope for this example.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Monadial\Nexus\Example\TicTacToe\Boot\App;
use Monadial\Nexus\Example\TicTacToe\Boot\Bootstrap;
use Monadial\Nexus\Example\TicTacToe\Boot\Config;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Runtime\Duration;

$config = Config::fromEnv();
$boot = Bootstrap::run($config);

try {
    SwooleWorkerServer::run(
        SwooleWorkerConfig::bind($config->http->host, $config->http->port)
            ->workers($config->http->threads)
            ->enableWebSocket()
            ->installSignalHandlers(false)
            ->shutdownTimeout(Duration::seconds(5)),
        App::factory($config),
    );
} catch (Throwable $e) {
    $boot->logger->critical('server crashed', [
        'error' => $e::class . ': ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);
}
