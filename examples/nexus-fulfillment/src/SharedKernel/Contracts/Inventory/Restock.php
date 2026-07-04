<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published command: replenish on-hand stock for an item.
 */
#[MessageType('inventory.restock.v1')]
final readonly class Restock
{
    public function __construct(
        public TenantId $tenantId,
        public Sku $sku,
        public Quantity $quantity,
    ) {}
}
