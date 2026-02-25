<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;

/**
 * @psalm-api
 *
 * A handle to a pending ask() result.
 *
 * Asks are eager — the request is sent at ask() call time.
 * await() suspends the current fiber until the reply arrives or the timeout fires.
 *
 * @template R
 */
final readonly class Future
{
    /**
     * @param FutureSlot|Closure(): R $source
     *
     * @psalm-suppress PossiblyInvalidPropertyAssignmentValue
     */
    public function __construct(private FutureSlot|Closure $source) {}

    /**
     * Await multiple futures. Returns when all resolve.
     *
     * Since asks are eager (sent at ask() time), sequential awaiting
     * still gives concurrent behavior — all requests are already in flight.
     *
     * @param Future<object> ...$futures
     * @return Future<list<object>>
     */
    public static function zip(self ...$futures): self
    {
        /** @var Future<list<object>> */
        return new self(static function () use ($futures): array {
            $results = [];

            foreach ($futures as $future) {
                $results[] = $future->await();
            }

            return $results;
        });
    }

    /**
     * Block the current fiber until the result is available.
     *
     * @return R
     * @throws \Monadial\Nexus\Core\Exception\AskTimeoutException
     */
    public function await(): mixed
    {
        if ($this->source instanceof FutureSlot) {
            /** @var R */
            return $this->source->await();
        }

        /** @var R */
        return ($this->source)();
    }

    public function isResolved(): bool
    {
        if ($this->source instanceof FutureSlot) {
            return $this->source->isResolved();
        }

        return false;
    }

    /**
     * Transform the result when it arrives. Lazy — does not block.
     *
     * @template U
     * @param Closure(R): U $fn
     * @return Future<U>
     */
    public function map(Closure $fn): self
    {
        $source = $this->source;

        /** @var Future<U> */
        return new self(static function () use ($source, $fn): mixed {
            /** @var R $value */
            $value = $source instanceof FutureSlot
                ? $source->await()
                : $source();

            return $fn($value);
        });
    }

    /**
     * Chain a dependent ask. Lazy — does not block.
     *
     * @template U
     * @param Closure(R): Future<U> $fn
     * @return Future<U>
     */
    public function flatMap(Closure $fn): self
    {
        $source = $this->source;

        /** @var Future<U> */
        return new self(static function () use ($source, $fn): mixed {
            /** @var R $value */
            $value = $source instanceof FutureSlot
                ? $source->await()
                : $source();

            return $fn($value)->await();
        });
    }
}
