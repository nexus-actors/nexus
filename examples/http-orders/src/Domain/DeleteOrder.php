<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

final readonly class DeleteOrder
{
    public function __construct(public int $id) {}
}
