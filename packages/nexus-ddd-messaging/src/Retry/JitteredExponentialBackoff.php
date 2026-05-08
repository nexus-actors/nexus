<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Retry;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Override;
use Throwable;

/**
 * @psalm-api
 */
final readonly class JitteredExponentialBackoff implements BackoffStrategy
{
    public function __construct(
        public FiniteDuration $base,
        public FiniteDuration $max,
        public float $multiplier = 2.0,
        public float $jitterFraction = 0.5,
    ) {}

    /**
     * @return Option<FiniteDuration>
     *
     * @psalm-suppress InvalidOperand
     */
    #[Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        $baseNanos = (int) round($this->base->toNanos() * ($this->multiplier ** $attempt));
        $clampedNanos = min($baseNanos, $this->max->toNanos());

        $randomFactor = (mt_rand(0, 200) - 100) / 100.0;
        $jitterNanos = (int) round(abs($randomFactor) * $this->jitterFraction * (float) $clampedNanos);

        $rawNanos = $randomFactor >= 0
            ? $clampedNanos + $jitterNanos
            : max(0, $clampedNanos - $jitterNanos);

        $delayedNanos = min($rawNanos, $this->max->toNanos());

        return Option::some(FiniteDuration::fromNanos($delayedNanos));
    }
}
