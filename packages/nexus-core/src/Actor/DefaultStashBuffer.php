<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Exception\StashOverflowException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Override;

/**
 * @psalm-api
 * @template T of object
 * @implements StashBuffer<T>
 */
final class DefaultStashBuffer implements StashBuffer
{
    /** @var list<Envelope> */
    private array $buffer = [];

    public function __construct(private readonly int $capacity) {}

    #[Override]
    public function stash(Envelope $envelope): void
    {
        if ($this->isFull()) {
            throw new StashOverflowException($this->capacity, $this->size());
        }

        $this->buffer[] = $envelope;
    }

    /**
     * @param Behavior<T> $targetBehavior
     * @return Behavior<T>
     */
    #[Override]
    public function unstashAll(Behavior $targetBehavior): Behavior
    {
        if ($this->isEmpty()) {
            return $targetBehavior;
        }

        $envelopes = $this->buffer;
        $this->buffer = [];

        return Behavior::unstashAllReplay($envelopes, $targetBehavior);
    }

    #[Override]
    public function isEmpty(): bool
    {
        return $this->buffer === [];
    }

    #[Override]
    public function isFull(): bool
    {
        return count($this->buffer) >= $this->capacity;
    }

    #[Override]
    public function size(): int
    {
        return count($this->buffer);
    }

    #[Override]
    public function capacity(): int
    {
        return $this->capacity;
    }
}
