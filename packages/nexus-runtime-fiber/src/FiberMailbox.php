<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use Closure;
use Fiber;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxOverflowException;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;
use NoDiscard;
use Override;
use SplQueue;

/**
 * @psalm-api
 * @template T of object
 * @implements Mailbox<T>
 */
final class FiberMailbox implements Mailbox
{
    /** @var SplQueue<T> */
    private SplQueue $queue;

    private bool $closed = false;

    /** @var list<Fiber<mixed, mixed, mixed, mixed>> */
    private array $waiters = [];

    /** @var array<int, true> */
    private array $waiterSet = [];

    /** @var ?Closure():void */
    private ?Closure $onEnqueue;

    /**
     * @param ?Closure():void $onEnqueue
     */
    public function __construct(private readonly MailboxConfig $config, ?callable $onEnqueue = null)
    {
        $this->onEnqueue = $onEnqueue !== null
            ? $onEnqueue(...)
            : null;
        /** @var SplQueue<T> $queue */
        $queue = new SplQueue();
        $this->queue = $queue;
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

        if ($this->config->bounded && $this->queue->count() >= $this->config->capacity) {
            return $this->handleOverflow($message);
        }

        $this->queue->enqueue($message);
        $this->resumeWaiter();

        if ($this->onEnqueue !== null) {
            ($this->onEnqueue)();
        }

        return EnqueueResult::Accepted;
    }

    /** @return Option<T> */
    #[Override]
    public function dequeue(): Option
    {
        if ($this->queue->isEmpty()) {
            /** @var Option<T> $none fp4php returns Option<empty>, covariant to Option<T> */
            $none = Option::none();

            return $none;
        }

        return Option::some($this->queue->dequeue());
    }

    /**
     * @throws MailboxClosedException
     */
    #[Override]
    /** @return T */
    public function dequeueBlocking(Duration $timeout): object
    {
        // Fiber-based blocking: suspend and re-check on resume
        $fiber = Fiber::getCurrent();

        if ($fiber !== null) {
            while (true) {
                if (!$this->queue->isEmpty()) {
                    return $this->queue->dequeue();
                }

                if ($this->closed) {
                    throw new MailboxClosedException();
                }

                // Register as waiter only if not already registered
                $fiberId = spl_object_id($fiber);

                if (!isset($this->waiterSet[$fiberId])) {
                    $this->waiters[] = $fiber;
                    $this->waiterSet[$fiberId] = true;
                }

                Fiber::suspend('mailbox_wait');
            }
        }

        // Fast path for non-fiber context
        if (!$this->queue->isEmpty()) {
            return $this->queue->dequeue();
        }

        if ($this->closed) {
            throw new MailboxClosedException();
        }

        // Not in a fiber context -- poll with timeout
        return $this->pollWithTimeout($timeout);
    }

    #[Override]
    public function count(): int
    {
        return $this->queue->count();
    }

    #[Override]
    public function isFull(): bool
    {
        if (!$this->config->bounded) {
            return false;
        }

        return $this->queue->count() >= $this->config->capacity;
    }

    #[Override]
    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    #[Override]
    public function close(): void
    {
        $this->closed = true;
        $this->wakeAllWaiters();
    }

    /**
     * @return list<Fiber<mixed, mixed, mixed, mixed>>
     */
    public function getWaiters(): array
    {
        return $this->waiters;
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
        $this->queue->dequeue();
        $this->queue->enqueue($message);

        return EnqueueResult::Accepted;
    }

    private function resumeWaiter(): void
    {
        if ($this->waiters !== []) {
            $fiber = array_shift($this->waiters);
            unset($this->waiterSet[spl_object_id($fiber)]);
            // The waiter will be resumed by the runtime event loop
        }
    }

    private function wakeAllWaiters(): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];
        $this->waiterSet = [];

        foreach ($waiters as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }
    }

    /**
     * @throws MailboxClosedException
     */
    /** @return T */
    private function pollWithTimeout(Duration $timeout): object
    {
        $deadline = hrtime(true) + $timeout->toNanos();

        while (hrtime(true) < $deadline) {
            if (!$this->queue->isEmpty()) {
                return $this->queue->dequeue();
            }

            if ($this->closed) {
                throw new MailboxClosedException();
            }

            usleep(100); // small sleep to avoid busy spin
        }

        throw new MailboxClosedException();
    }
}
