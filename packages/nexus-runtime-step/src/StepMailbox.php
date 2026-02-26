<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Step;

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
 * Deterministic mailbox for the StepRuntime.
 *
 * Unlike FiberMailbox, dequeueBlocking() ALWAYS suspends the fiber before
 * checking the queue. This guarantees that each message requires an explicit
 * step() call from the test to be processed.
 *
 * @psalm-api
 * @template T of object
 * @implements Mailbox<T>
 */
final class StepMailbox implements Mailbox
{
    /** @var SplQueue<T> */
    private SplQueue $queue;

    private bool $closed = false;

    /** @var ?Fiber<mixed, mixed, mixed, mixed> */
    private ?Fiber $waitingFiber = null;

    public function __construct(private readonly MailboxConfig $config)
    {
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

        return EnqueueResult::Accepted;
    }

    /** @return Option<T> */
    #[Override]
    public function dequeue(): Option
    {
        if ($this->queue->isEmpty()) {
            /** @var Option<T> $none */
            $none = Option::none();

            return $none;
        }

        return Option::some($this->queue->dequeue());
    }

    /**
     * Always suspends the fiber, then checks for messages on resume.
     * This ensures exactly one message is processed per step() call.
     *
     * @throws MailboxClosedException
     */
    #[Override]
    /** @return T */
    public function dequeueBlocking(Duration $timeout): object
    {
        $fiber = Fiber::getCurrent();

        if ($fiber !== null) {
            while (true) {
                // Always suspend — give control back to step()
                $this->waitingFiber = $fiber;
                Fiber::suspend('step_wait');
                $this->waitingFiber = null;

                if (!$this->queue->isEmpty()) {
                    return $this->queue->dequeue();
                }

                if ($this->closed) {
                    throw new MailboxClosedException();
                }
            }
        }

        // Non-fiber fallback: return immediately if available
        if (!$this->queue->isEmpty()) {
            return $this->queue->dequeue();
        }

        throw new MailboxClosedException();
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

        // Wake the waiting fiber so it can see the closed state
        if ($this->waitingFiber !== null && $this->waitingFiber->isSuspended()) {
            $fiber = $this->waitingFiber;
            $this->waitingFiber = null;
            $fiber->resume();
        }
    }

    public function hasWaitingFiber(): bool
    {
        return $this->waitingFiber !== null && $this->waitingFiber->isSuspended();
    }

    /**
     * @return ?Fiber<mixed, mixed, mixed, mixed>
     */
    public function getWaitingFiber(): ?Fiber
    {
        return $this->waitingFiber;
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
}
