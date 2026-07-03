<?php

declare(strict_types=1);

/**
 * Fulfillment entry point — boots the Swoole worker server. Worker
 * (process) mode, matching the tictactoe example: WebSocket channel
 * actors (a later milestone) require per-process shared memory.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Monadial\Nexus\Example\Fulfillment\Platform\Boot\App;
use Monadial\Nexus\Example\Fulfillment\Platform\Boot\FulfillmentConfig;
use Monadial\Nexus\Example\Fulfillment\Platform\Boot\StderrLogger;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Runtime\Duration;

$config = FulfillmentConfig::fromEnv();
$log = StderrLogger::create('server');

try {
    SwooleWorkerServer::run(
        SwooleWorkerConfig::bind($config->http->host, $config->http->port)
            ->workers($config->http->workers)
            ->installSignalHandlers(false)
            ->shutdownTimeout(Duration::seconds(5)),
        App::factory($config),
    );
} catch (Throwable $e) {
    $log->critical('server crashed', [
        'error' => $e::class . ': ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);
}
