<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Throwable;

/**
 * @psalm-api
 *
 * User-supplied backoff strategy via callable. Full control.
 */
final class CustomBackoff implements BackoffStrategy
{
    /** @var callable(int, Throwable): Option */
    private $fn;

    /** @param callable(int, Throwable): Option $fn */
    private function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    /** @param callable(int, Throwable): Option $fn */
    public static function of(callable $fn): self
    {
        return new self($fn);
    }

    #[\Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return ($this->fn)($attempt, $cause);
    }
}
