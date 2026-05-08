<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Retry;

use Closure;
use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Open extension point — wraps an arbitrary `(int, Throwable) -> Option<FiniteDuration>` callable.
 */
final readonly class CustomBackoff implements BackoffStrategy
{
    /** @var Closure(int, Throwable): Option<FiniteDuration> */
    private Closure $compute;

    /**
     * @param callable(int, Throwable): Option<FiniteDuration> $compute
     */
    public function __construct(callable $compute)
    {
        $this->compute = Closure::fromCallable($compute);
    }

    /**
     * @return Option<FiniteDuration>
     */
    #[Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return ($this->compute)($attempt, $cause);
    }
}
