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
     * Create a Future that is already completed with the given value.
     *
     * @template R2 of object
     * @param R2 $value
     * @return self<R2>
     */
    public static function resolved(object $value): self
    {
        /** @var ImmediateFutureSlot<R2> $slot */
        $slot = new ImmediateFutureSlot();
        $slot->resolve($value);

        return new self($slot);
    }

    /**
     * Create a Future that is already failed.
     *
     * @return self<object>
     */
    public static function failed(FutureException $error): self
    {
        $slot = new ImmediateFutureSlot();
        $slot->fail($error);

        return new self($slot);
    }

    /**
     * Wait for all futures and collect their results, keyed identically to the input.
     * If any future fails, the combined future fails with the first failure encountered.
     *
     * @param array<array-key, Future<object>> $futures
     * @return Future<FutureResult<object>>
     */
    public static function all(array $futures): self
    {
        if ($futures === []) {
            /** @var FutureResult<object> $empty */
            $empty = new FutureResult([]);

            return self::resolved($empty);
        }

        /** @var FutureSlot<FutureResult<object>> $combined */
        $combined = new LazyFutureSlot(static function () use ($futures): FutureResult {
            $values = [];

            foreach ($futures as $key => $future) {
                $values[$key] = $future->await();
            }

            return new FutureResult($values);
        });

        return new self($combined);
    }

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
