<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\Duration;
use Throwable;

/**
 * @psalm-api
 *
 * User-supplied backoff strategy via callable. Full control.
 */
final class CustomBackoff implements BackoffStrategy
{
    /** @var callable(int, Throwable): Option<Duration> */
    private $fn;

    /** @param callable(int, Throwable): Option<Duration> $fn */
    private function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    /** @param callable(int, Throwable): Option<Duration> $fn */
    public static function of(callable $fn): self
    {
        return new self($fn);
    }

    /** @return Option<Duration> */
    #[\Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return ($this->fn)($attempt, $cause);
    }
}
