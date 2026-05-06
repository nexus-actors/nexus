<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Throwable;

/**
 * @psalm-api
 *
 * Exponential backoff with uniform jitter in [0, base) added per attempt.
 * Recommended default for high-fan-in retry paths to avoid thundering-herd.
 *
 * Note: this is NOT @psalm-immutable because delayFor() uses random jitter.
 */
final readonly class JitteredExponentialBackoff implements BackoffStrategy
{
    private function __construct(
        public Duration $base,
        public Duration $cap,
        public int $maxAttempts,
    ) {}

    public static function of(Duration $base, Duration $cap, int $maxAttempts): self
    {
        return new self($base, $cap, $maxAttempts);
    }

    #[\Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        if ($attempt > $this->maxAttempts) {
            return Option::none();
        }
        $exponential = (int) round($this->base->toMillis() * (2 ** ($attempt - 1)));
        $clamped = min($exponential, $this->cap->toMillis());
        $jitter = random_int(0, $this->base->toMillis());

        return Option::some(FiniteDuration::fromTimeUnit(
            $clamped + $jitter,
            TimeUnit::Milliseconds(),
        ));
    }
}
