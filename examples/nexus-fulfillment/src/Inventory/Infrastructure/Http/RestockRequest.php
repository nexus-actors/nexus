<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\Http;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;

final readonly class RestockRequest
{
    public function __construct(public Quantity $quantity) {}
}
