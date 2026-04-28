<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

final readonly class Order
{
    public function __construct(public int $id, public string $sku, public int $qty) {}
}
