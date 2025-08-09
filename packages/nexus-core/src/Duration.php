<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core;

use Brick\Math\BigInteger;

/**
 * Nanosecond-precision duration.
 * Stub — full implementation in Task 3.
 */
final readonly class Duration implements \Stringable
{
    private function __construct(
        private BigInteger $nanos,
    ) {}

    public static function seconds(int $seconds): self
    {
        return new self(BigInteger::of($seconds)->multipliedBy(1_000_000_000));
    }

    public static function millis(int $millis): self
    {
        return new self(BigInteger::of($millis)->multipliedBy(1_000_000));
    }

    public function __toString(): string
    {
        return $this->nanos . 'ns';
    }
}
