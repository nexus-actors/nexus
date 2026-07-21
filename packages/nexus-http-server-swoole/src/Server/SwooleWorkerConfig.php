<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Immutable configuration for SwooleWorkerServer::run().
 * Constructed via the static bind() entry; further tunables return
 * new instances via PHP 8.5 clone-with.
 */
final readonly class SwooleWorkerConfig
{
    /** Default maximum HTTP request package size: 8 MiB. */
    public const int DEFAULT_MAX_REQUEST_BODY_BYTES = 8 * 1024 * 1024;

    /**
     * @param array<string, mixed> $swooleSettings Extra keys merged into
     *        `$server->set()`. Applied BEFORE the framework's own defaults so
     *        those defaults win on conflict — except keys the framework does
     *        not set (e.g. `websocket_compression`), which you can override
     *        here. Use `withSwooleSetting()` to populate immutably.
     */
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
        public bool $enableWebSocket,
        public int $maxRequestBodyBytes = self::DEFAULT_MAX_REQUEST_BODY_BYTES,
        public array $swooleSettings = [],
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
            enableWebSocket: false,
            maxRequestBodyBytes: self::DEFAULT_MAX_REQUEST_BODY_BYTES,
            swooleSettings: [],
        );
    }

    /**
     * Merge raw Swoole server settings (e.g. re-enable `websocket_compression`
     * on a zlib-enabled build, tune `buffer_output_size`, set `ssl_cert_file`).
     *
     * @param array<string, mixed> $settings
     */
    public function withSwooleSetting(array $settings): self
    {
        return clone($this, ['swooleSettings' => [...$this->swooleSettings, ...$settings]]);
    }

    public function dispatchMode(int $mode): self
    {
        return clone($this, ['dispatchMode' => $mode]);
    }

    public function enableWebSocket(bool $b = true): self
    {
        return clone($this, ['enableWebSocket' => $b]);
    }

    public function installSignalHandlers(bool $b): self
    {
        return clone($this, ['installSignalHandlers' => $b]);
    }

    public function logFile(string $path): self
    {
        return clone($this, ['logFile' => $path]);
    }

    public function logger(LoggerInterface $log): self
    {
        return clone($this, ['logger' => $log]);
    }

    public function maxConn(int $n): self
    {
        return clone($this, ['maxConn' => $n]);
    }

    /**
     * Cap the maximum HTTP request package size (bytes). Swoole rejects a
     * larger request at the protocol parser — before PHP materializes the body
     * via rawContent() — for every method and for both known-length and
     * chunked/streaming bodies, bounding per-request memory. Wired to Swoole's
     * native `package_max_length`. Raise it above your largest legitimate
     * upload; keep it as low as your API allows to tighten the memory-DoS
     * surface.
     */
    public function maxRequestBodyBytes(int $bytes): self
    {
        return clone($this, ['maxRequestBodyBytes' => $bytes]);
    }

    public function maxRequest(int $n): self
    {
        return clone($this, ['maxRequest' => $n]);
    }

    public function reactorThreads(int $n): self
    {
        return clone($this, ['reactorThreads' => $n]);
    }

    public function shutdownTimeout(Duration $d): self
    {
        return clone($this, ['shutdownTimeout' => $d]);
    }

    public function workers(int $n): self
    {
        return clone($this, ['workers' => $n]);
    }
}
