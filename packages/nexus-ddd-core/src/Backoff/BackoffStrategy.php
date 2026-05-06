<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\Duration;
use Throwable;

/**
 * @psalm-api
 *
 * Foundational primitive for retry timing.
 *
 * Used by OCC retry middleware, outbox relay, async transport retries, process
 * manager retries, and any application-level retry need.
 */
interface BackoffStrategy
{
    /**
     * @param int $attempt 1-based — first failure is attempt #1
     * @return Option<Duration> none = give up; some = wait this long before next try
     */
    public function delayFor(int $attempt, Throwable $cause): Option;
}
