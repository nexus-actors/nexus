<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Immutable configuration for SwooleWorkerHttpServer::run().
 * Constructed via the static bind() entry; further tunables return
 * new instances.
 */
final readonly class SwooleWorkerConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public int $workers,
        public int $reactorThreads,
        public int $maxRequest,
        public int $maxConn,
        public int $dispatchMode,
        public Duration $shutdownTimeout,
        public bool $installSignalHandlers,
        public LoggerInterface $logger,
        public string $logFile,
    ) {}

    public static function bind(string $host, int $port = 8080): self
    {
        return new self(
            host: $host,
            port: $port,
            workers: 1,
            reactorThreads: 0,
            maxRequest: 0,
            maxConn: 0,
            dispatchMode: 2,
            shutdownTimeout: Duration::seconds(10),
            installSignalHandlers: true,
            logger: new NullLogger(),
            logFile: '',
        );
    }

    public function dispatchMode(int $mode): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->workers,
            $this->reactorThreads,
            $this->maxRequest,
            $this->maxConn,
            $mode,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $this->logger,
            $this->logFile,
        );
    }

    public function installSignalHandlers(bool $b): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->workers,
            $this->reactorThreads,
            $this->maxRequest,
            $this->maxConn,
            $this->dispatchMode,
            $this->shutdownTimeout,
            $b,
            $this->logger,
            $this->logFile,
        );
    }

    public function logFile(string $path): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->workers,
            $this->reactorThreads,
            $this->maxRequest,
            $this->maxConn,
            $this->dispatchMode,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $this->logger,
            $path,
        );
    }

    public function logger(LoggerInterface $log): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->workers,
            $this->reactorThreads,
            $this->maxRequest,
            $this->maxConn,
            $this->dispatchMode,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $log,
            $this->logFile,
        );
    }

    public function maxConn(int $n): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->workers,
            $this->reactorThreads,
            $this->maxRequest,
            $n,
            $this->dispatchMode,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $this->logger,
            $this->logFile,
        );
    }

    public function maxRequest(int $n): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->workers,
            $this->reactorThreads,
            $n,
            $this->maxConn,
            $this->dispatchMode,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $this->logger,
            $this->logFile,
        );
    }

    public function reactorThreads(int $n): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->workers,
            $n,
            $this->maxRequest,
            $this->maxConn,
            $this->dispatchMode,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $this->logger,
            $this->logFile,
        );
    }

    public function shutdownTimeout(Duration $d): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->workers,
            $this->reactorThreads,
            $this->maxRequest,
            $this->maxConn,
            $this->dispatchMode,
            $d,
            $this->installSignalHandlers,
            $this->logger,
            $this->logFile,
        );
    }

    public function workers(int $n): self
    {
        return new self(
            $this->host,
            $this->port,
            $n,
            $this->reactorThreads,
            $this->maxRequest,
            $this->maxConn,
            $this->dispatchMode,
            $this->shutdownTimeout,
            $this->installSignalHandlers,
            $this->logger,
            $this->logFile,
        );
    }
}
