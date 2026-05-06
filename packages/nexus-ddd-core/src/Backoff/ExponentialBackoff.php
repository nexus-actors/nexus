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
 * @psalm-immutable
 *
 * Delay = min(cap, base × multiplier^(attempt-1)). Default multiplier = 2.0.
 */
final readonly class ExponentialBackoff implements BackoffStrategy
{
    private function __construct(
        public Duration $base,
        public Duration $cap,
        public int $maxAttempts,
        public float $multiplier,
    ) {}

    public static function of(
        Duration $base,
        Duration $cap,
        int $maxAttempts,
        float $multiplier = 2.0,
    ): self {
        return new self($base, $cap, $maxAttempts, $multiplier);
    }

    #[\Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        if ($attempt > $this->maxAttempts) {
            return Option::none();
        }
        $millis = (int) round($this->base->toMillis() * ($this->multiplier ** ($attempt - 1)));
        $clamped = min($millis, $this->cap->toMillis());

        return Option::some(FiniteDuration::fromTimeUnit($clamped, TimeUnit::Milliseconds()));
    }
}
