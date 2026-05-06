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
 * Delay = base × attempt (so attempt 1 waits `base`, attempt 2 waits `2 × base`, etc.).
 */
final readonly class LinearBackoff implements BackoffStrategy
{
    private function __construct(
        public Duration $base,
        public int $maxAttempts,
    ) {}

    public static function of(Duration $base, int $maxAttempts): self
    {
        return new self($base, $maxAttempts);
    }

    #[\Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        if ($attempt > $this->maxAttempts) {
            return Option::none();
        }

        return Option::some(FiniteDuration::fromTimeUnit(
            $this->base->toMillis() * $attempt,
            TimeUnit::Milliseconds(),
        ));
    }
}
