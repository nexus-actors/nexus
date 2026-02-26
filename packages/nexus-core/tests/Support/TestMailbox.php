<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxOverflowException;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

use function assert;

/** @implements Mailbox<Envelope> */
final class TestMailbox implements Mailbox
{
    /** @var list<Envelope> */
    private array $queue = [];
    private bool $closed = false;

    public function __construct(private readonly MailboxConfig $config) {}

    public static function unbounded(): self
    {
        return new self(MailboxConfig::unbounded());
    }

    public static function bounded(int $capacity, OverflowStrategy $strategy = OverflowStrategy::ThrowException): self
    {
        return new self(MailboxConfig::bounded($capacity, $strategy));
    }

    /**
     * @throws MailboxClosedException
     */
    public function enqueue(object $message): EnqueueResult
    {
        assert($message instanceof Envelope);
        $envelope = $message;

        if ($this->closed) {
            throw new MailboxClosedException();
        }

        if ($this->config->bounded && count($this->queue) >= $this->config->capacity) {
            return match ($this->config->strategy) {
                OverflowStrategy::DropNewest => EnqueueResult::Dropped,
                OverflowStrategy::DropOldest => $this->dropOldestAndEnqueue($envelope),
                OverflowStrategy::Backpressure => EnqueueResult::Backpressured,
                OverflowStrategy::ThrowException => throw new MailboxOverflowException(
                    $this->config->capacity,
                    $this->config->strategy,
                ),
            };
        }

        $this->queue[] = $envelope;

        return EnqueueResult::Accepted;
    }

    /** @return Option<Envelope> */
    public function dequeue(): Option
    {
        if ($this->queue === []) {
            /** @var Option<Envelope> $none */
            $none = Option::none();

            return $none;
        }

        $envelope = array_shift($this->queue);

        return Option::some($envelope);
    }

    /**
     * @throws MailboxClosedException
     */
    public function dequeueBlocking(Duration $timeout): Envelope
    {
        if ($this->closed && $this->queue === []) {
            throw new MailboxClosedException();
        }

        // In test runtime, blocking just returns next or throws
        if ($this->queue === []) {
            throw new MailboxClosedException();
        }

        return array_shift($this->queue);
    }

    public function count(): int
    {
        return count($this->queue);
    }

    public function isFull(): bool
    {
        return $this->config->bounded && count($this->queue) >= $this->config->capacity;
    }

    public function isEmpty(): bool
    {
        return $this->queue === [];
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function dropOldestAndEnqueue(Envelope $envelope): EnqueueResult
    {
        array_shift($this->queue);
        $this->queue[] = $envelope;

        return EnqueueResult::Accepted;
    }
}
