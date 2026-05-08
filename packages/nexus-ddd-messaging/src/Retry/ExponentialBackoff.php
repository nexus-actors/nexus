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
final readonly class ExponentialBackoff implements BackoffStrategy
{
    public function __construct(
        public FiniteDuration $base,
        public FiniteDuration $max,
        public float $multiplier = 2.0,
    ) {}

    /**
     * @return Option<FiniteDuration>
     */
    #[Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        $candidateNanos = (int) round($this->base->toNanos() * ($this->multiplier ** $attempt));
        $clampedNanos = min($candidateNanos, $this->max->toNanos());

        return Option::some(FiniteDuration::fromNanos($clampedNanos));
    }
}
