<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

/**
 * @psalm-type LineShape = array{currency: string, quantity: int, sku: string, unitPriceCents: int}
 */
final readonly class PlaceOrderRequest
{
    /**
     * @param non-empty-list<PlaceOrderLine> $lines
     */
    public function __construct(
        public array $lines,
        public string $orderId,
    ) {}
}
