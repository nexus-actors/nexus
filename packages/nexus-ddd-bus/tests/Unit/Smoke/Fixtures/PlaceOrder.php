<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Messaging\Message\Command;

final readonly class PlaceOrder implements Command
{
    public function __construct(public string $customerId, public string $orderId) {}
}
