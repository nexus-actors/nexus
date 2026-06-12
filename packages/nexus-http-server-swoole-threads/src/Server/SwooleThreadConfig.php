<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Immutable configuration for SwooleThreadHttpServer::run().
 * Constructed via the static bind() entry; further tunables return new instances.
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
        );
    }

    public function installSignalHandlers(bool $b): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->threads,
            $this->maxRequest,
            $this->shutdownTimeout,
            $b,
            $this->logger,
        );
    }

    public function logger(LoggerInterface $log): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->threads,
            $this->maxRequest,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $log,
        );
    }

    public function maxRequest(int $n): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->threads,
            $n,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $this->logger,
        );
    }

    public function shutdownTimeout(Duration $d): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->threads,
            $this->maxRequest,
            $d,
            $this->installSignalHandlers,
            $this->logger,
        );
    }

    public function threads(int $n): self
    {
        return new self(
            $this->host,
            $this->port,
            $n,
            $this->maxRequest,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $this->logger,
        );
    }
}
