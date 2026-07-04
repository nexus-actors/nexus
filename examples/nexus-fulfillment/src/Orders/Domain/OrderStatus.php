<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain;

enum OrderStatus: string
{
    case NotCreated = 'not_created';
    case Placed = 'placed';
    case StockReserved = 'stock_reserved';
    case Cancelled = 'cancelled';
}
