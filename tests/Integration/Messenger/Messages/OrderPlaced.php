<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger\Messages;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('order-placed')]
final readonly class OrderPlaced
{
    public function __construct(public string $orderId) {}
}
