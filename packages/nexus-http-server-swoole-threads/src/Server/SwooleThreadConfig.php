<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** @psalm-api */
final readonly class SwooleThreadConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public int $maxRequest,
        public Duration $shutdownTimeout,
        public LoggerInterface $logger,
    ) {}

    public static function bind(string $host, int $port = 8080): self
    {
        return new self($host, $port, 0, Duration::seconds(10), new NullLogger());
    }

    public function logger(LoggerInterface $log): self
    {
        return new self($this->host, $this->port, $this->maxRequest, $this->shutdownTimeout, $log);
    }

    public function maxRequest(int $n): self
    {
        return new self($this->host, $this->port, $n, $this->shutdownTimeout, $this->logger);
    }

    public function shutdownTimeout(Duration $d): self
    {
        return new self($this->host, $this->port, $this->maxRequest, $d, $this->logger);
    }
}
