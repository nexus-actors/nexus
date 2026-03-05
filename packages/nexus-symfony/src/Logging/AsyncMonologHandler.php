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
 * context. Call stop() during graceful shutdown to flush remaining records —
 * stop() blocks until the consumer has finished draining the channel.
 *
 * @psalm-api
 */
final class AsyncMonologHandler implements HandlerInterface
{
    private ?Channel $channel = null;
    private ?Channel $done = null;
    private bool $closed = false;
    private bool $started = false;

    /**
     * @param int $capacity Channel capacity. 0 means effectively unbounded (uses PHP_INT_MAX internally).
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
            if ($this->channel->push($record, 0.001) === false) {
                return $this->inner->handle($record);
            }

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
        if ($this->closed) {
            return;
        }

        $this->closed = true;
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

        $channelCapacity = $this->capacity > 0
            ? $this->capacity
            : PHP_INT_MAX;
        $this->channel   = new Channel($channelCapacity);
        $this->done      = new Channel(1);
        $this->started   = true;

        $channel = $this->channel;
        $done    = $this->done;
        $inner   = $this->inner;

        Coroutine::create(static function () use ($channel, $done, $inner): void {
            while (true) {
                $record = $channel->pop();

                if (!$record instanceof LogRecord) {
                    break;
                }

                $inner->handle($record);
            }

            $done->push(true);
        });
    }

    /**
     * Sends stop sentinel to the consumer coroutine and waits for it to drain.
     */
    public function stop(): void
    {
        if (!$this->started || $this->channel === null || $this->done === null) {
            return;
        }

        $done    = $this->done;
        $channel = $this->channel;

        $this->channel = null;
        $this->done    = null;
        $this->started = false;

        $channel->push(null);
        $done->pop();
    }

    public function isStarted(): bool
    {
        return $this->started;
    }
}
