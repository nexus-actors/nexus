<?php

declare(strict_types=1);

namespace App\Actor\Message;

final readonly class ProductList
{
    /** @param Product[] $items */
    public function __construct(public array $items) {}
}
