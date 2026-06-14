<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Immutable configuration for SwooleThreadServer::run().
 * Constructed via the static bind() entry; further tunables return new
 * instances via PHP 8.5 clone-with.
 */
final readonly class SwooleThreadConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public int $threads,
        public int $maxRequest,
        public Duration $shutdownTimeout,
        public bool $installSignalHandlers,
        public LoggerInterface $logger,
        public bool $enableWebSocket,
    ) {}

    public static function bind(string $host, int $port = 8080): self
    {
        return new self(
            host: $host,
            port: $port,
            threads: 1,
            maxRequest: 0,
            shutdownTimeout: Duration::seconds(10),
            installSignalHandlers: true,
            logger: new NullLogger(),
            enableWebSocket: false,
        );
    }

    public function enableWebSocket(bool $b = true): self
    {
        return clone($this, ['enableWebSocket' => $b]);
    }

    public function installSignalHandlers(bool $b): self
    {
        return clone($this, ['installSignalHandlers' => $b]);
    }

    public function logger(LoggerInterface $log): self
    {
        return clone($this, ['logger' => $log]);
    }

    public function maxRequest(int $n): self
    {
        return clone($this, ['maxRequest' => $n]);
    }

    public function shutdownTimeout(Duration $d): self
    {
        return clone($this, ['shutdownTimeout' => $d]);
    }

    public function threads(int $n): self
    {
        return clone($this, ['threads' => $n]);
    }
}
