<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

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
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Swoole-backed mailbox using a Swoole\Coroutine\Channel for message passing.
 *
 * "Unbounded" configs are physically capped by the channel at 65,536 slots
 * (exposed via {@see SwooleMailbox::$effectiveCapacity}). Admission is
 * truthful: every push result is inspected, and a full channel surfaces the
 * configured overflow strategy — for unbounded configs that strategy is
 * ThrowException, so hitting the physical cap throws MailboxOverflowException
 * instead of silently losing the message.
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

    /** The real slot count of the underlying channel, also for "unbounded" configs. */
    public readonly int $effectiveCapacity;

    private Channel $channel;

    private bool $closed = false;

    /** @var SplQueue<T> */
    private SplQueue $drainQueue;

    public function __construct(private readonly MailboxConfig $config)
    {
        $this->effectiveCapacity = $this->config->bounded
            ? $this->config->capacity
            : self::UNBOUNDED_CAPACITY;

        $this->channel = new Channel($this->effectiveCapacity);

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

        if ($this->channel->length() >= $this->effectiveCapacity) {
            return $this->handleOverflow($message);
        }

        if (Coroutine::getCid() === -1) {
            // Outside coroutine context (e.g. Swoole WorkerStop hook).
            // Channel::push() requires a coroutine to suspend in, so wrap
            // the push in a fresh one. Accepted is best-effort here: the
            // fullness pre-check above already ran, and the push lands
            // asynchronously inside the new coroutine. The only producer
            // that runs out-of-coroutine is the shutdown sequence
            // broadcasting PoisonPill — message ordering across the
            // no-coroutine window doesn't matter for that.
            $channel = $this->channel;
            $nonBlockingTimeout = self::NON_BLOCKING_TIMEOUT;
            Coroutine::create(static function () use ($channel, $message, $nonBlockingTimeout): void {
                $channel->push($message, $nonBlockingTimeout);
            });

            return EnqueueResult::Accepted;
        }

        return $this->pushInspected($message);
    }

    /** @return T|null */
    #[Override]
    public function dequeue(): mixed
    {
        // After close, drain from the backup queue
        if ($this->closed) {
            if (!$this->drainQueue->isEmpty()) {
                return $this->drainQueue->dequeue();
            }

            return null;
        }

        if ($this->channel->isEmpty()) {
            return null;
        }

        /** @var T|false $result */
        $result = $this->channel->pop(self::NON_BLOCKING_TIMEOUT);

        if ($result === false) {
            return null;
        }

        return $result;
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
        if ($this->closed) {
            return $this->drainQueue->count() >= $this->effectiveCapacity;
        }

        return $this->channel->length() >= $this->effectiveCapacity;
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
    public function isClosed(): bool
    {
        return $this->closed;
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
     * Push with the result inspected: a failed push never reports Accepted.
     * A push that loses the fullness race maps to the overflow strategy
     * without retrying (DropOldest degrades to Dropped rather than looping).
     *
     * @param T $message
     * @throws MailboxClosedException
     * @throws MailboxOverflowException
     */
    private function pushInspected(object $message): EnqueueResult
    {
        if ($this->channel->push($message, self::NON_BLOCKING_TIMEOUT)) {
            return EnqueueResult::Accepted;
        }

        if ($this->channel->errCode === SWOOLE_CHANNEL_CLOSED) {
            throw new MailboxClosedException();
        }

        return match ($this->config->strategy) {
            OverflowStrategy::DropNewest, OverflowStrategy::DropOldest => EnqueueResult::Dropped,
            OverflowStrategy::Backpressure => EnqueueResult::Backpressured,
            OverflowStrategy::ThrowException => throw new MailboxOverflowException(
                $this->effectiveCapacity,
                $this->config->strategy,
            ),
        };
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
                $this->effectiveCapacity,
                $this->config->strategy,
            ),
        };
    }

    /**
     * @param T $message
     */
    private function dropOldestAndEnqueue(object $message): EnqueueResult
    {
        // Pop the oldest message (non-blocking); if even that fails, the new
        // message cannot be admitted — report the drop instead of lying.
        if ($this->channel->pop(self::NON_BLOCKING_TIMEOUT) === false) {
            return EnqueueResult::Dropped;
        }

        if (!$this->channel->push($message, self::NON_BLOCKING_TIMEOUT)) {
            return EnqueueResult::Dropped;
        }

        return EnqueueResult::Accepted;
    }
}
