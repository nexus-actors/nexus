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
final readonly class FixedDelayBackoff implements BackoffStrategy
{
    public function __construct(public FiniteDuration $delay) {}

    /**
     * @return Option<FiniteDuration>
     */
    #[Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return Option::some($this->delay);
    }
}
