<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

/**
 * One line of an order: what, how many, at what unit price.
 */
final readonly class OrderLine
{
    public function __construct(
        public Sku $sku,
        public Quantity $quantity,
        public Money $unitPrice,
    ) {}

    public function total(): Money
    {
        return $this->unitPrice->multiplyBy($this->quantity->value);
    }
}
