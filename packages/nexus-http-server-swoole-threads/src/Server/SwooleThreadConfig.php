<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\Thread\Queue;

/**
 * @psalm-api
 *
 * Immutable configuration for SwooleThreadServer::run().
 * Constructed via the static bind() entry; further tunables return new
 * instances via PHP 8.5 clone-with.
 */
final readonly class SwooleThreadConfig
{
    /** Default maximum HTTP request package size: 8 MiB. */
    public const int DEFAULT_MAX_REQUEST_BODY_BYTES = 8 * 1024 * 1024;

    /**
     * @param array<string, mixed> $swooleSettings
     */
    public function __construct(
        public string $host,
        public int $port,
        public int $threads,
        public int $maxRequest,
        public Duration $shutdownTimeout,
        public bool $installSignalHandlers,
        public LoggerInterface $logger,
        public bool $enableWebSocket,
        public ?Queue $logQueue = null,
        public int $maxRequestBodyBytes = self::DEFAULT_MAX_REQUEST_BODY_BYTES,
        public array $swooleSettings = [],
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
            logQueue: null,
            maxRequestBodyBytes: self::DEFAULT_MAX_REQUEST_BODY_BYTES,
            swooleSettings: [],
        );
    }

    /**
     * Merge arbitrary Swoole server settings into the `$server->set([...])`
     * call. Use for tcp_nodelay, tcp_defer_accept, socket_buffer_size,
     * backlog, etc. — see website/docs/http/performance.md for the full list.
     *
     * Framework-controlled keys (max_request, worker_num, init_arguments)
     * always win; user settings can't override them.
     *
     * @param array<string, mixed> $settings
     */
    public function withSwooleSetting(array $settings): self
    {
        return clone($this, ['swooleSettings' => [...$this->swooleSettings, ...$settings]]);
    }

    /**
     * Pass a pre-allocated Swoole\Thread\Queue to be shared across all
     * worker threads. Threads access it via Swoole\Thread::getArguments()[3].
     * Pair with ThreadQueueHandler + a dedicated writer thread for
     * lock-free file logging at high throughput.
     */
    public function withLogQueue(Queue $queue): self
    {
        return clone($this, ['logQueue' => $queue]);
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

    /**
     * Cap the maximum HTTP request package size (bytes). Swoole rejects a
     * larger request at the protocol parser — before PHP materializes the body
     * — for every method and both known-length and chunked bodies. Wired to
     * Swoole's native `package_max_length`.
     */
    public function maxRequestBodyBytes(int $bytes): self
    {
        return clone($this, ['maxRequestBodyBytes' => $bytes]);
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
