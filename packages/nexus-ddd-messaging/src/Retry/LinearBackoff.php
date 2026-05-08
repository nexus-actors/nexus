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
 * Linear backoff — delay = base * attempt. **`$attempt` must be 1-indexed**
 * (the first retry is attempt 1; passing 0 returns a zero delay, which is
 * a degenerate hot-loop). Production retry loops should start counting
 * from 1.
 */
final readonly class LinearBackoff implements BackoffStrategy
{
    public function __construct(public FiniteDuration $base) {}

    /**
     * @return Option<FiniteDuration>
     */
    #[Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return Option::some(FiniteDuration::fromNanos($this->base->toNanos() * $attempt));
    }
}
