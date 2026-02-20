<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization\Messages;

use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-type CartItemArray = array{productId: string, quantity: int, price: float}
 */
#[MessageType('cart.updated')]
final readonly class CartUpdated
{
    /**
     * @param list<CartItem> $items
     */
    public function __construct(public string $cartId, public array $items)
    {
    }
}
