<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain;

use InvalidArgumentException;

/**
 * Cent-precision money value object. Tracking integers avoids float
 * accumulation error and matches how event-sourced ledgers should
 * persist amounts (the event log is the source of truth, so the
 * unit must be exact).
 */
final readonly class Money
{
    public function __construct(public int $cents)
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money must be non-negative; got ' . $cents);
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function isLessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }
}
