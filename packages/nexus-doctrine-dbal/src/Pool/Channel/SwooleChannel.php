<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool\Channel;

use Monadial\Nexus\Runtime\Duration;
use Override;
use Swoole\Coroutine\Channel as SwooleCoroutineChannel;

/**
 * Coroutine-aware bounded channel. `pop()` suspends the current coroutine
 * until either an item is pushed or the timeout elapses.
 *
 * @template T of object
 * @template-implements Channel<T>
 * @psalm-api
 */
final class SwooleChannel implements Channel
{
    /** Minimum positive timeout for non-blocking push (Swoole treats 0.0 as block-forever). */
    private const float NON_BLOCKING_TIMEOUT = 0.001;

    private SwooleCoroutineChannel $channel;

    public function __construct(int $capacity)
    {
        $this->channel = new SwooleCoroutineChannel($capacity);
    }

    #[Override]
    public function push(object $item): bool
    {
        return $this->channel->push($item, self::NON_BLOCKING_TIMEOUT) === true;
    }

    #[Override]
    public function pop(Duration $timeout): ?object
    {
        $seconds = $timeout->toSecondsFloat() === 0.0
            ? self::NON_BLOCKING_TIMEOUT
            : $timeout->toSecondsFloat();

        /** @var T|false $result */
        $result = $this->channel->pop($seconds);

        if ($result === false) {
            return null;
        }

        return $result;
    }

    #[Override]
    public function size(): int
    {
        return (int) $this->channel->length();
    }

    #[Override]
    public function close(): void
    {
        $this->channel->close();
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->channel->errCode === SWOOLE_CHANNEL_CLOSED;
    }
}
