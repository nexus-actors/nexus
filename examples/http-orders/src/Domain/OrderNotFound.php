<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

final readonly class OrderNotFound
{
    public function __construct(public int $id) {}
}
