<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization\Messages;

final readonly class CartItem
{
    public function __construct(public string $productId, public int $quantity, public float $price) {}
}
