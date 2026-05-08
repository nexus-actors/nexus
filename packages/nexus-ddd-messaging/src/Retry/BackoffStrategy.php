<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Retry;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Throwable;

/**
 * @psalm-api
 *
 * Decides whether and how long to wait before retrying a failed dispatch.
 * Returns Some<FiniteDuration> to retry; None to give up.
 */
interface BackoffStrategy
{
    /**
     * @return Option<FiniteDuration>
     */
    public function delayFor(int $attempt, Throwable $cause): Option;
}
