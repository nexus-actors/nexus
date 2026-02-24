<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Exception\MailboxOverflowException;
use Monadial\Nexus\Core\Exception\MailboxTimeoutException;
use Monadial\Nexus\Core\Mailbox\EnqueueResult;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;
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
 */
final class SwooleMailbox implements Mailbox
{
    private const int UNBOUNDED_CAPACITY = 65536;

    /** Minimum positive timeout for non-blocking Channel operations (seconds). */
    private const float NON_BLOCKING_TIMEOUT = 0.001;

    private Channel $channel;

    private bool $closed = false;

    /** @var SplQueue<Envelope> */
    private SplQueue $drainQueue;

    public function __construct(private readonly MailboxConfig $config, private readonly ActorPath $actor)
    {
        $capacity = $this->config->bounded
            ? $this->config->capacity
            : self::UNBOUNDED_CAPACITY;

        $this->channel = new Channel($capacity);

        /** @var SplQueue<Envelope> $queue */
        $queue = new SplQueue();
        $this->drainQueue = $queue;
    }

    /**
     * @throws MailboxClosedException
     */
    #[Override]
    #[NoDiscard]
    public function enqueue(Envelope $envelope): EnqueueResult
    {
        if ($this->closed) {
            throw new MailboxClosedException($this->actor);
        }

        if ($this->config->bounded && $this->channel->length() >= $this->config->capacity) {
            return $this->handleOverflow($envelope);
        }

        $this->channel->push($envelope, self::NON_BLOCKING_TIMEOUT);

        return EnqueueResult::Accepted;
    }

    /** @return Option<Envelope> */
    #[Override]
    public function dequeue(): Option
    {
        // After close, drain from the backup queue
        if ($this->closed) {
            if (!$this->drainQueue->isEmpty()) {
                return Option::some($this->drainQueue->dequeue());
            }

            /** @var Option<Envelope> $none */
            $none = Option::none();

            return $none;
        }

        if ($this->channel->isEmpty()) {
            /** @var Option<Envelope> $none */
            $none = Option::none();

            return $none;
        }

        /** @var Envelope|false $result */
        $result = $this->channel->pop(self::NON_BLOCKING_TIMEOUT);

        if ($result === false) {
            /** @var Option<Envelope> $none */
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
    public function dequeueBlocking(Duration $timeout): Envelope
    {
        // Fast path: check drain queue first when closed
        if ($this->closed) {
            if (!$this->drainQueue->isEmpty()) {
                return $this->drainQueue->dequeue();
            }

            throw new MailboxClosedException($this->actor);
        }

        $timeoutSeconds = $timeout->toSecondsFloat();

        /** @var Envelope|false $result */
        $result = $this->channel->pop($timeoutSeconds);

        if ($result === false) {
            /** @var int $errCode */
            $errCode = $this->channel->errCode;

            if ($errCode === SWOOLE_CHANNEL_CLOSED) {
                throw new MailboxClosedException($this->actor);
            }

            throw new MailboxTimeoutException($this->actor, $timeout);
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
            /** @var Envelope|false $item */
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
    private function handleOverflow(Envelope $envelope): EnqueueResult
    {
        return match ($this->config->strategy) {
            OverflowStrategy::DropNewest => EnqueueResult::Dropped,
            OverflowStrategy::DropOldest => $this->dropOldestAndEnqueue($envelope),
            OverflowStrategy::Backpressure => EnqueueResult::Backpressured,
            OverflowStrategy::ThrowException => throw new MailboxOverflowException(
                $this->actor,
                $this->config->capacity,
                $this->config->strategy,
            ),
        };
    }

    private function dropOldestAndEnqueue(Envelope $envelope): EnqueueResult
    {
        // Pop the oldest message (non-blocking)
        $this->channel->pop(self::NON_BLOCKING_TIMEOUT);
        // Push the new message
        $this->channel->push($envelope, self::NON_BLOCKING_TIMEOUT);

        return EnqueueResult::Accepted;
    }
}
