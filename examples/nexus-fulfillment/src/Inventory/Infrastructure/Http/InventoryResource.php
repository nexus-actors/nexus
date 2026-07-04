<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\Http;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;

final readonly class InventoryResource
{
    public function __construct(
        public Sku $sku,
        public int $onHand,
        public int $available,
    ) {}
}
