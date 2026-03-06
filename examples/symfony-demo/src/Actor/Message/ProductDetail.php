<?php

declare(strict_types=1);

namespace App\Actor\Message;

final readonly class ProductDetail
{
    public function __construct(public Product $product) {}
}
