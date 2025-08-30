<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use Fiber;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Exception\MailboxOverflowException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\EnqueueResult;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;

final class FiberMailbox implements Mailbox
{
    /** @var \SplQueue<Envelope> */
    private \SplQueue $queue;

    private bool $closed = false;

    /** @var list<Fiber<mixed, mixed, mixed, mixed>> */
    private array $waiters = [];

    public function __construct(
        private readonly MailboxConfig $config,
        private readonly ActorPath $actor,
    ) {
        /** @var \SplQueue<Envelope> $queue */
        $queue = new \SplQueue();
        $this->queue = $queue;
    }

    /**
     * @throws MailboxClosedException
     */
    #[\NoDiscard]
    public function enqueue(Envelope $envelope): EnqueueResult
    {
        if ($this->closed) {
            throw new MailboxClosedException($this->actor);
        }

        if ($this->config->bounded && $this->queue->count() >= $this->config->capacity) {
            return $this->handleOverflow($envelope); // @phpstan-ignore missingType.checkedException
        }

        $this->queue->enqueue($envelope);
        $this->resumeWaiter();

        return EnqueueResult::Accepted;
    }

    /** @return Option<Envelope> */
    public function dequeue(): Option
    {
        if ($this->queue->isEmpty()) {
            /** @var Option<Envelope> $none fp4php returns Option<empty>, covariant to Option<Envelope> */
            $none = Option::none(); // @phpstan-ignore varTag.type

            return $none;
        }

        return Option::some($this->queue->dequeue());
    }

    /**
     * @throws MailboxClosedException
     */
    public function dequeueBlocking(Duration $timeout): Envelope
    {
        if (!$this->queue->isEmpty()) {
            return $this->queue->dequeue();
        }

        if ($this->closed) {
            throw new MailboxClosedException($this->actor);
        }

        // Suspend the current fiber and wait for a message
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            $this->waiters[] = $fiber;
            Fiber::suspend('mailbox_wait');

            // After resume, check if we were woken due to close
            if ($this->queue->isEmpty()) { // @phpstan-ignore if.alwaysTrue
                throw new MailboxClosedException($this->actor);
            }

            return $this->queue->dequeue(); // @phpstan-ignore deadCode.unreachable
        }

        // Not in a fiber context -- poll with timeout
        return $this->pollWithTimeout($timeout);
    }

    public function count(): int
    {
        return $this->queue->count();
    }

    public function isFull(): bool
    {
        if (!$this->config->bounded) {
            return false;
        }

        return $this->queue->count() >= $this->config->capacity;
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

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

    private function resumeWaiter(): void
    {
        if ($this->waiters !== []) {
            array_shift($this->waiters);
            // The waiter will be resumed by the runtime event loop
        }
    }

    private function wakeAllWaiters(): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }
    }

    /**
     * @throws MailboxClosedException
     */
    private function pollWithTimeout(Duration $timeout): Envelope
    {
        $deadline = hrtime(true) + $timeout->toNanos();
        while (hrtime(true) < $deadline) {
            if (!$this->queue->isEmpty()) {
                return $this->queue->dequeue();
            }
            if ($this->closed) {
                throw new MailboxClosedException($this->actor);
            }
            usleep(100); // small sleep to avoid busy spin
        }

        throw new MailboxClosedException($this->actor);
    }
}
