<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Async;

use Closure;

/**
 * @psalm-api
 *
 * A handle to a pending async result.
 *
 * Asks are eager — the request is sent at ask() call time.
 * await() suspends the current fiber until the reply arrives or the timeout fires.
 *
 * @template R of object
 */
final readonly class Future
{
    public function __construct(private FutureSlot $slot) {}

    /**
     * Block the current fiber until the result is available.
     *
     * @return R
     * @throws \Monadial\Nexus\Core\Exception\AskTimeoutException
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

    /**
     * Transform the result when it arrives. Lazy — does not block.
     *
     * @template U of object
     * @param Closure(R): U $fn
     * @return Future<U>
     */
    public function map(Closure $fn): self
    {
        $slot = $this->slot;

        /** @var Future<U> */
        return new self(new LazyFutureSlot(static function () use ($slot, $fn): object {
            /** @var R $value */
            $value = $slot->await();

            return $fn($value);
        }));
    }

    /**
     * Chain a dependent ask. Lazy — does not block.
     *
     * @template U of object
     * @param Closure(R): Future<U> $fn
     * @return Future<U>
     */
    public function flatMap(Closure $fn): self
    {
        $slot = $this->slot;

        /** @var Future<U> */
        return new self(new LazyFutureSlot(static function () use ($slot, $fn): object {
            /** @var R $value */
            $value = $slot->await();

            return $fn($value)->await();
        }));
    }
}
