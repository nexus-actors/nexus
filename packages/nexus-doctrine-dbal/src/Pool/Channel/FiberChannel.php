<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool\Channel;

use Monadial\Nexus\Runtime\Duration;
use Override;
use SplQueue;

/**
 * Non-blocking, single-fiber channel backed by SplQueue. `pop()` ignores
 * the timeout — under FiberRuntime PDO will block the fiber anyway, so
 * adding a coroutine-style suspend here is pointless.
 *
 * @template T of object
 * @template-implements Channel<T>
 * @psalm-api
 */
final class FiberChannel implements Channel
{
    /** @var SplQueue<T> */
    private SplQueue $queue;
    private bool $closed = false;

    public function __construct(private readonly int $capacity)
    {
        /** @var SplQueue<T> $queue */
        $queue = new SplQueue();
        $this->queue = $queue;
    }

    #[Override]
    public function push(object $item): bool
    {
        if ($this->closed) {
            return false;
        }

        if ($this->queue->count() >= $this->capacity) {
            return false;
        }

        $this->queue->enqueue($item);

        return true;
    }

    #[Override]
    public function pop(Duration $timeout): ?object
    {
        if ($this->closed || $this->queue->isEmpty()) {
            return null;
        }

        return $this->queue->dequeue();
    }

    #[Override]
    public function size(): int
    {
        return $this->queue->count();
    }

    #[Override]
    public function close(): void
    {
        $this->closed = true;
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
