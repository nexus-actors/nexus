<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Admin;

use Swoole\Coroutine\Http\Server as HttpServer;

/**
 * Wraps a Swoole coroutine HTTP server for admin API access.
 *
 * Must be started inside a coroutine context (Co\run()).
 *
 * @psalm-api
 */
final class AdminServer
{
    private ?HttpServer $httpServer = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly AdminHandler $handler = new AdminHandler(),
    ) {}

    public function start(): void
    {
        $this->httpServer = new HttpServer($this->host, $this->port);

        $this->httpServer->handle('/api/', $this->handler->handle(...));
        $this->httpServer->handle('/', $this->handler->handle(...));

        $this->httpServer->start();
    }

    public function shutdown(): void
    {
        if ($this->httpServer !== null) {
            $this->httpServer->shutdown();
            $this->httpServer = null;
        }
    }
}
