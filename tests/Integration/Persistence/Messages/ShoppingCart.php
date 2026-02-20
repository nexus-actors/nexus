<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Persistence\Messages;

final readonly class ShoppingCart
{
    /**
     * @param list<string> $items
     */
    public function __construct(public array $items = [])
    {
    }
}
