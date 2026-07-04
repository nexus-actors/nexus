<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;

final readonly class StockCommandRejected
{
    public function __construct(
        public Sku $sku,
        public string $reason,
    ) {}
}
