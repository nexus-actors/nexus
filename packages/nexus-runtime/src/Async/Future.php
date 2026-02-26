<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Async;

use Closure;
use Monadial\Nexus\Runtime\Exception\FutureException;

/**
 * @psalm-api
 *
 * A handle to a pending async result.
 *
 * Asks are eager - the request is sent at ask() call time.
 * await() suspends the current fiber until the reply arrives or the timeout fires.
 *
 * @template R of object
 */
final readonly class Future
{
    /**
     * @param FutureSlot<R> $slot
     */
    public function __construct(private FutureSlot $slot) {}

    /**
     * Block the current fiber until the result is available.
     *
     * @return R
     * @throws FutureException
     */
    public function await(): object
    {
        /** @var R */
        return $this->slot->await();
    }

    public function isResolved(): bool
    {
        return $this->slot->isResolved();
    }

    public function cancel(): void
    {
        $this->slot->cancel();
    }

    /**
     * Register a callback invoked when this future is cancelled.
     *
     * @param Closure(): void $callback
     */
    public function onCancel(Closure $callback): self
    {
        $this->slot->onCancel($callback);

        return $this;
    }

    /**
     * Transform the result when it arrives. Lazy - does not block.
     *
     * @template U of object
     * @param Closure(R): U $fn
     * @return Future<U>
     */
    public function map(Closure $fn): self
    {
        $slot = $this->slot;

        /** @var FutureSlot<U> $mappedSlot */
        $mappedSlot = new LazyFutureSlot(static function () use ($slot, $fn): object {
            $value = $slot->await();

            return $fn($value);
        });

        return new self($mappedSlot);
    }

    /**
     * Chain a dependent ask. Lazy - does not block.
     *
     * @template U of object
     * @param Closure(R): Future<U> $fn
     * @return Future<U>
     */
    public function flatMap(Closure $fn): self
    {
        $slot = $this->slot;

        /** @var FutureSlot<U> $mappedSlot */
        $mappedSlot = new LazyFutureSlot(static function () use ($slot, $fn): object {
            $value = $slot->await();

            return $fn($value)->await();
        });

        return new self($mappedSlot);
    }
}
