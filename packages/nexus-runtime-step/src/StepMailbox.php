<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Step;

use Fiber;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Exception\MailboxOverflowException;
use Monadial\Nexus\Core\Mailbox\EnqueueResult;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;
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
 */
final class StepMailbox implements Mailbox
{
    /** @var \SplQueue<Envelope> */
    private SplQueue $queue;

    private bool $closed = false;

    /** @var ?Fiber<mixed, mixed, mixed, mixed> */
    private ?Fiber $waitingFiber = null;

    public function __construct(private readonly MailboxConfig $config, private readonly ActorPath $actor,) {
        /** @var \SplQueue<Envelope> $queue */
        $queue = new SplQueue();
        $this->queue = $queue;
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

        if ($this->config->bounded && $this->queue->count() >= $this->config->capacity) {
            return $this->handleOverflow($envelope);
        }

        $this->queue->enqueue($envelope);

        return EnqueueResult::Accepted;
    }

    /** @return Option<Envelope> */
    #[Override]
    public function dequeue(): Option
    {
        if ($this->queue->isEmpty()) {
            /** @var Option<Envelope> $none */
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
    public function dequeueBlocking(Duration $timeout): Envelope
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
                    throw new MailboxClosedException($this->actor);
                }
            }
        }

        // Non-fiber fallback: return immediately if available
        if (!$this->queue->isEmpty()) {
            return $this->queue->dequeue();
        }

        throw new MailboxClosedException($this->actor);
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
        $this->queue->dequeue();
        $this->queue->enqueue($envelope);

        return EnqueueResult::Accepted;
    }
}
