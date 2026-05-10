<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use Throwable;

use function sprintf;

/**
 * @psalm-api
 *
 * Raised when the OCC retry middleware exhausts its attempt budget
 * without success. Implements `RetryableFailure` so the caller higher up
 * MAY retry at the application level (different bus, different command
 * envelope, etc.) — but this individual attempt sequence is done.
 */
final class RetryBudgetExhaustedException extends BusRuntimeException implements RetryableFailure
{
    public static function for(int $attempts, int $budgetMs, Throwable $cause): self
    {
        return new self(
            sprintf('Retry budget exhausted after %d attempts within %d ms.', $attempts, $budgetMs),
            0,
            $cause,
        );
    }
}
