<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

use InvalidArgumentException;

use function preg_match;

/**
 * An amount of money in minor units (cents) of an ISO-4217 currency.
 * Arithmetic never mixes currencies.
 */
final readonly class Money
{
    public function __construct(public int $amount, public string $currency)
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Invalid currency code: '{$currency}'");
        }
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot add {$other->currency} to {$this->currency}",
            );
        }

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function multiplyBy(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException("Cannot multiply money by negative factor {$factor}");
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
