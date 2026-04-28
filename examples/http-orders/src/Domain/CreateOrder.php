<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

final readonly class CreateOrder
{
    public function __construct(public string $sku, public int $qty) {}
}
