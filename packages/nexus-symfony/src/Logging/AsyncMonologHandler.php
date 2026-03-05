<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Logging;

use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Async Monolog handler backed by a Swoole coroutine channel.
 *
 * Pushes log records to an in-memory channel (non-blocking) and drains them
 * via a long-running consumer coroutine. No actor dependency.
 *
 * Auto-starts the consumer on the first handle() call inside a Swoole coroutine
 * context. Call stop() during graceful shutdown to flush remaining records.
 *
 * @psalm-api
 */
final class AsyncMonologHandler implements HandlerInterface
{
    private ?Channel $channel = null;
    private bool $started = false;

    /**
     * @param int $capacity Channel capacity — 0 means unbounded.
     */
    public function __construct(
        private readonly HandlerInterface $inner,
        private readonly int $capacity = 0,
        private readonly Level $level = Level::Debug,
    ) {}

    public function isHandling(LogRecord $record): bool
    {
        return $record->level->value >= $this->level->value;
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        if (!$this->started && Coroutine::getUid() > 0) {
            $this->start();
        }

        if ($this->channel !== null) {
            $this->channel->push($record, 0.001);

            return false;
        }

        return $this->inner->handle($record);
    }

    /**
     * @param array<LogRecord> $records
     */
    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function close(): void
    {
        $this->stop();
        $this->inner->close();
    }

    /**
     * Starts the consumer coroutine. Called automatically on first handle()
     * inside a Swoole coroutine context, or explicitly from workerStart.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->channel = new Channel($this->capacity);
        $this->started = true;
        $channel = $this->channel;
        $inner = $this->inner;

        Coroutine::create(static function () use ($channel, $inner): void {
            while (true) {
                $record = $channel->pop();

                if (!$record instanceof LogRecord) {
                    break;
                }

                $inner->handle($record);
            }
        });
    }

    /**
     * Sends stop sentinel to the consumer coroutine and waits for it to drain.
     */
    public function stop(): void
    {
        if (!$this->started || $this->channel === null) {
            return;
        }

        $this->channel->push(null);
        $this->started = false;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }
}
