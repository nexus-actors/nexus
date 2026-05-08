<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Retry;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Exponential backoff with additive symmetric jitter. The delay is
 * `base * multiplier^attempt`, capped at `max`, with a uniform-random
 * ±jitterFraction perturbation applied symmetrically.
 *
 * **Jitter shape limitation:** this is symmetric ± jitter, not Marc Brooker's
 * "decorrelated jitter" (uniform between base and 3×prev-attempt). For
 * high-concurrency retry storms (many nodes retrying the same downstream
 * simultaneously), decorrelated jitter spreads retries better; consumers
 * should use `CustomBackoff` with their preferred formula in that case.
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
