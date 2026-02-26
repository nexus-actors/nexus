<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxOverflowException;
use Monadial\Nexus\Runtime\Exception\MailboxTimeoutException;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;
use NoDiscard;
use Override;
use SplQueue;
use Swoole\Coroutine\Channel;

/**
 * Swoole-backed mailbox using a Swoole\Coroutine\Channel for message passing.
 *
 * On close(), remaining messages are drained from the channel into an internal
 * SplQueue backup so they can still be dequeued after the channel is closed.
 *
 * Note: Swoole Channel pop(0.0) blocks indefinitely because 0.0 is treated as
 * falsy, falling back to the default -1 timeout. We use a tiny positive epsilon
 * (0.001s) for non-blocking operations instead.
 *
 * @psalm-api
 * @template T of object
 * @implements Mailbox<T>
 */
final class SwooleMailbox implements Mailbox
{
    private const int UNBOUNDED_CAPACITY = 65536;

    /** Minimum positive timeout for non-blocking Channel operations (seconds). */
    private const float NON_BLOCKING_TIMEOUT = 0.001;

    private Channel $channel;

    private bool $closed = false;

    /** @var SplQueue<T> */
    private SplQueue $drainQueue;

    public function __construct(private readonly MailboxConfig $config)
    {
        $capacity = $this->config->bounded
            ? $this->config->capacity
            : self::UNBOUNDED_CAPACITY;

        $this->channel = new Channel($capacity);

        /** @var SplQueue<T> $queue */
        $queue = new SplQueue();
        $this->drainQueue = $queue;
    }

    /**
     * @throws MailboxClosedException
     * @param T $message
     */
    #[Override]
    #[NoDiscard]
    public function enqueue(object $message): EnqueueResult
    {
        if ($this->closed) {
            throw new MailboxClosedException();
        }

        if ($this->config->bounded && $this->channel->length() >= $this->config->capacity) {
            return $this->handleOverflow($message);
        }

        $this->channel->push($message, self::NON_BLOCKING_TIMEOUT);

        return EnqueueResult::Accepted;
    }

    /** @return Option<T> */
    #[Override]
    public function dequeue(): Option
    {
        // After close, drain from the backup queue
        if ($this->closed) {
            if (!$this->drainQueue->isEmpty()) {
                return Option::some($this->drainQueue->dequeue());
            }

            /** @var Option<T> $none */
            $none = Option::none();

            return $none;
        }

        if ($this->channel->isEmpty()) {
            /** @var Option<T> $none */
            $none = Option::none();

            return $none;
        }

        /** @var T|false $result */
        $result = $this->channel->pop(self::NON_BLOCKING_TIMEOUT);

        if ($result === false) {
            /** @var Option<T> $none */
            $none = Option::none();

            return $none;
        }

        return Option::some($result);
    }

    /**
     * @throws MailboxClosedException
     * @throws MailboxTimeoutException
     */
    #[Override]
    /** @return T */
    public function dequeueBlocking(Duration $timeout): object
    {
        // Fast path: check drain queue first when closed
        if ($this->closed) {
            if (!$this->drainQueue->isEmpty()) {
                return $this->drainQueue->dequeue();
            }

            throw new MailboxClosedException();
        }

        $timeoutSeconds = $timeout->toSecondsFloat();

        /** @var T|false $result */
        $result = $this->channel->pop($timeoutSeconds);

        if ($result === false) {
            /** @var int $errCode */
            $errCode = $this->channel->errCode;

            if ($errCode === SWOOLE_CHANNEL_CLOSED) {
                throw new MailboxClosedException();
            }

            throw new MailboxTimeoutException($timeout);
        }

        return $result;
    }

    #[Override]
    public function count(): int
    {
        if ($this->closed) {
            return $this->drainQueue->count();
        }

        /** @var int */
        return $this->channel->length();
    }

    #[Override]
    public function isFull(): bool
    {
        if (!$this->config->bounded) {
            return false;
        }

        if ($this->closed) {
            return $this->drainQueue->count() >= $this->config->capacity;
        }

        return $this->channel->length() >= $this->config->capacity;
    }

    #[Override]
    public function isEmpty(): bool
    {
        if ($this->closed) {
            return $this->drainQueue->isEmpty();
        }

        /** @var bool */
        return $this->channel->isEmpty();
    }

    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // Drain remaining messages from the channel into the backup queue
        // before closing the channel (which discards remaining items).
        while (!$this->channel->isEmpty()) {
            /** @var T|false $item */
            $item = $this->channel->pop(self::NON_BLOCKING_TIMEOUT);

            if ($item !== false) {
                $this->drainQueue->enqueue($item);
            }
        }

        $this->channel->close();
    }

    /**
     * @throws MailboxOverflowException
     */
    /**
     * @param T $message
     */
    private function handleOverflow(object $message): EnqueueResult
    {
        return match ($this->config->strategy) {
            OverflowStrategy::DropNewest => EnqueueResult::Dropped,
            OverflowStrategy::DropOldest => $this->dropOldestAndEnqueue($message),
            OverflowStrategy::Backpressure => EnqueueResult::Backpressured,
            OverflowStrategy::ThrowException => throw new MailboxOverflowException(
                $this->config->capacity,
                $this->config->strategy,
            ),
        };
    }

    /**
     * @param T $message
     */
    private function dropOldestAndEnqueue(object $message): EnqueueResult
    {
        // Pop the oldest message (non-blocking)
        $this->channel->pop(self::NON_BLOCKING_TIMEOUT);
        // Push the new message
        $this->channel->push($message, self::NON_BLOCKING_TIMEOUT);

        return EnqueueResult::Accepted;
    }
}
