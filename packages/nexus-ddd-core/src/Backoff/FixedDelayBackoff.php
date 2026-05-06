<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\Duration;
use Throwable;

/**
 * @psalm-api
 */
final readonly class FixedDelayBackoff implements BackoffStrategy
{
    private function __construct(
        public Duration $delay,
        public int $maxAttempts,
    ) {}

    public static function of(Duration $delay, int $maxAttempts): self
    {
        return new self($delay, $maxAttempts);
    }

    #[\Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        if ($attempt > $this->maxAttempts) {
            return Option::none();
        }

        return Option::some($this->delay);
    }
}
