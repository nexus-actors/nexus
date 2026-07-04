<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

final readonly class PlaceOrderLine
{
    public function __construct(
        public string $currency,
        public int $quantity,
        public string $sku,
        public int $unitPriceCents,
    ) {}
}
